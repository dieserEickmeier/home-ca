# Home CA

Lokale Zertifizierungsstelle für dein Heimnetz. PHP + Vanilla JS + OpenSSL-CLI, läuft als Docker-Container.

## Funktionen

- Root-CA erzeugen (RSA oder EC, konfigurierbare Gültigkeit)
- CSRs serverseitig erzeugen (inkl. privatem Schlüssel zum Download) **oder** vorhandene CSRs einreichen
- Warteschlange: CSRs genehmigen oder ablehnen, SANs beim Genehmigen überschreibbar
- Export ausgestellter Zertifikate als PEM, PEM-Chain (Zertifikat + Root), DER, PKCS12/PFX (passwortgeschützt) oder privater Schlüssel
- Widerruf ausgestellter Zertifikate mit echter **CRL** (automatisch neu erzeugt bei Ausstellung/Widerruf, öffentlich ohne Login abrufbar) und **OCSP-Responder** (Port 2560)
- CRL-/OCSP-URLs konfigurierbar, werden automatisch als Extensions in neu ausgestellte Zertifikate eingebettet
- **Auto-Renew**: Zertifikate vor Ablauf automatisch neu signieren (gleicher CSR/Key) und per NPM-API direkt in Nginx Proxy Manager hochladen — läuft per Cron im Container, kein manueller Eingriff nötig
- **Ablauf-Warnung** im Dashboard für Zertifikate ohne Auto-Renew, die in ≤14 Tagen ablaufen
- **Ein-Klick-Backup** (.zip mit CA-Key, Zertifikat, Datenbank, CRL) direkt aus dem Dashboard
- **Bulk-Genehmigung** aller wartenden CSRs auf einmal
- **Pushover-Benachrichtigungen** bei Renewal-/Push-Fehlern und OCSP-Ausfall
- **Health-Check-Endpoint** (`?action=health`, kein Login) für Monitoring-Tools wie Uptime Kuma
- Admin-Passwort-Login

## Start

```bash
mkdir -p ../home-ca-data
cp .env.example .env
# ADMIN_PASSWORD in .env anpassen
docker compose up -d --build
```

Interface unter `http://<host>:8443`. Beim ersten Login CA anlegen.

**Wichtig:** Datenordner liegt bewusst **außerhalb** dieses Projektordners (`../home-ca-data`, ein Verzeichnis höher). Grund: `rm -r` auf diesem Projektordner oder ein Update per "alten Ordner löschen, neues ZIP entpacken" darf niemals CA-Key und Zertifikate mitreißen. Diesen Ordner separat sichern (siehe unten) — er ist der einzige Ort, an dem der private CA-Schlüssel liegt.

## CRL & OCSP

Nach dem Anlegen der CA im Dashboard-Panel "Widerruf — CRL & OCSP" die Basis-URLs eintragen, unter denen Clients im Heimnetz diesen Host erreichen — **nicht** `127.0.0.1` oder `localhost`, sondern die tatsächliche IP/Hostname:

- **CRL-URL**: `http://<host>:8443/api.php?action=crl_download`
- **OCSP-URL**: `http://<host>:2560`

Diese URLs werden ab dem nächsten genehmigten Zertifikat automatisch als `crlDistributionPoints`- bzw. `authorityInfoAccess`-Extension eingebettet. Bereits ausgestellte Zertifikate sind davon nicht betroffen (Extensions sind pro Zertifikat fix, nicht nachträglich änderbar).

**CRL** wird automatisch bei jeder Genehmigung und jedem Widerruf neu erzeugt, öffentlich abrufbar ohne Login (wie bei jeder echten CA — Clients müssen sie ohne Anmeldung laden können).

**OCSP-Responder** läuft als eigener `openssl ocsp`-Prozess im Container auf Port 2560, per `docker-compose.yml` nach außen exponiert. Er lädt seine Datenbank nur beim Start; ein Supervisor-Skript im Container (`entrypoint.sh`) beobachtet die Widerrufsliste und startet ihn bei Änderung automatisch neu (Prüfintervall 3 Sekunden) — kein manuelles Eingreifen nötig.

## Auto-Renew & Nginx Proxy Manager

Zertifikate können automatisch vor Ablauf erneuert und direkt in NPM hochgeladen werden — läuft per Cron alle 6 Stunden im Container (`busybox crond`, kein zusätzlicher Dienst nötig).

**Einrichtung:**

1. Dashboard → Panel "NPM-Integration": NPM-Basis-URL (z. B. `http://192.168.1.10:81`), Login-E-Mail und Passwort eintragen, "Verbindung testen" klicken.
2. In NPM selbst einmalig ein "Custom"-Zertifikat für den Host anlegen (Platzhalter reicht, z. B. das aktuell exportierte PEM+Key manuell hochladen) und dem Proxy-Host zuweisen — die App braucht die NPM-interne Zertifikat-ID als Ziel.
3. Zertifikate-Tab → betroffenes Zertifikat → "Auto-Renew" → Häkchen setzen, Vorlauf in Tagen (Standard 30), NPM-Zertifikat aus Dropdown wählen, speichern.
4. Mit "Jetzt erneuern & pushen" sofort testen, statt auf den nächsten Cron-Lauf zu warten.

**Wie es funktioniert:** Erneuerung signiert denselben gespeicherten CSR erneut (gleicher Key, neue Seriennummer, neue Gültigkeit) — kein erneutes manuelles Genehmigen nötig, da beim Aktivieren von Auto-Renew implizit vorab genehmigt. Push erfolgt über die NPM-REST-API (`/api/nginx/certificates/{id}/upload`). Der reine Upload-Endpunkt lädt nginx bei NPM nicht zuverlässig automatisch neu (bestätigtes Verhalten, nicht nur Vermutung) — die App speichert deshalb im Anschluss alle Proxy-Hosts, die auf dieses Zertifikat zeigen, unverändert per API neu, was bei NPM zuverlässig Config-Regenerierung + Reload auslöst.

**Wichtige Einschränkung:** Push nach NPM braucht den privaten Schlüssel serverseitig gespeichert. Zertifikate, deren Schlüssel über "Schlüssel löschen" entfernt wurde, oder die aus einem hochgeladenen fremden CSR stammen (kein Schlüssel je auf dem Server), können nicht automatisch gepusht werden — nur automatisch erneuert (falls das für den Anwendungsfall reicht) oder gar nicht, je nachdem was fehlt.

**Live-Check nach jedem Push:** Die App verbindet sich per TLS zum Hostnamen des Zertifikats (Port 443) und vergleicht die dort tatsächlich ausgelieferte Seriennummer mit der erwarteten. Ergebnis erscheint direkt im Toast ("Live-Check bestätigt" / "Live-Check zeigt Abweichung") und wird in der Verlaufs-Historie gespeichert — deckt genau den Fall ab, den wir beim NPM-Reload-Gap sonst erst manuell per `openssl s_client` nachprüfen mussten. Funktioniert nur, wenn der Host von diesem Container aus per TLS erreichbar ist.

**Verlaufs-Historie:** Auto-Renew-Modal zeigt die letzten 10 Erneuerungen pro Zertifikat (alte → neue Serial, wann, Push-/Live-Check-Status).

**NPM-Totalausfall-Erkennung:** Wenn bei einem Cron-Lauf mehrere Zertifikate mit demselben NPM-bezogenen Fehler scheitern, schickt die App eine Sammelmeldung statt einer Pushover-Nachricht pro betroffenem Host.

### Wiederherstellung nach Datenverlust: "Von NPM importieren"

Falls die CA-Datenbank verloren geht (z. B. versehentlich gelöschter Datenordner), aber NPM selbst noch die alten Custom-Zertifikat-Einträge und darauf verweisende Proxy-Hosts hat: Dashboard → NPM-Integration → "Von NPM importieren". Liest alle Custom-Zertifikate aus NPM aus (Let's-Encrypt-Einträge werden ausgeblendet), schlägt CN/SANs anhand der dort hinterlegten Domainnamen vor, und legt beim Bestätigen für jedes ausgewählte NPM-Zertifikat ein neues CSR an — direkt verknüpft mit der jeweiligen NPM-Zertifikat-ID, Auto-Renew aktiviert.

Ablauf danach: Warteschlange → jedes CSR genehmigen → Zertifikate-Tab → Auto-Renew-Modal → "Nur zu NPM pushen". Da dieselbe NPM-Zertifikat-ID wiederverwendet wird, auf die die Proxy-Hosts in NPM bereits zeigen, ist in NPM selbst keine Neukonfiguration nötig — nur die Dateien werden ausgetauscht (plus automatischer Reload-Trigger, siehe oben).

## Backup

Dashboard → "Backup (.zip)" — lädt CA-Key, CA-Zertifikat, SQLite-Datenbank, CRL und Index-DB als ein Archiv herunter. Nicht automatisiert, bewusst manuell (Erinnerung: nach jeder wichtigen Änderung ausführen, oder per Cron außerhalb des Containers regelmäßig abrufen).

Separat davon: Zertifikate-Tab → "Alle exportieren (.zip)" — nur die ausgestellten Zertifikate (Chain + Key, falls vorhanden), pro Host in einem eigenen Ordner, für Dokumentation oder Migration auf eine andere CA-Instanz. Kein Ersatz für das echte Backup oben (kein CA-Key drin).

## Benachrichtigungen (Pushover)

Dashboard → Panel "Benachrichtigungen (Pushover)": App + User Key auf [pushover.net](https://pushover.net) anlegen, hier eintragen, "Aktiv" anhaken. Danach automatische Meldungen bei:

- Fehlgeschlagener automatischer Erneuerung oder NPM-Push (pro Cron-Lauf gesammelt, eine Nachricht statt Spam)
- OCSP-Responder nicht erreichbar (Port 2560)

"Test senden" funktioniert auch, wenn "Aktiv" noch nicht angehakt ist — reine Verbindungsprüfung.

## Health-Check

`GET /api.php?action=health` — kein Login nötig, für externe Monitoring-Tools (Uptime Kuma, Healthchecks.io etc.). Liefert HTTP 200 + `{"status":"ok",...}` wenn CA vorhanden, OCSP erreichbar und CRL nicht überfällig ist, sonst HTTP 503 + `{"status":"degraded",...}` mit Detail-Flags.

## Datenablage

Alles liegt in `../home-ca-data` (ein Verzeichnis über diesem Projektordner, siehe oben):

- `ca.db` — SQLite, alle CSRs/Zertifikate/Metadaten, NPM-Zugangsdaten
- `ca/ca.key`, `ca/ca.crt` — Root-CA-Schlüssel und -Zertifikat
- `ca/ca.srl` — Seriennummern-Zähler
- `ca/index.txt` — Widerrufsdatenbank für CRL/OCSP (aus der SQLite-DB abgeleitet, bei jeder Änderung neu geschrieben)
- `ca/crl.pem`, `ca/crlnumber` — aktuelle CRL und ihr Zähler

**Backup = diesen Ordner sichern, regelmäßig, außerhalb des Hosts falls möglich.** `ca.key` ist der kritischste Wert im ganzen System — einmal weg, ist auch jedes bereits ausgestellte Zertifikat wertlos (keine neue Signatur unter derselben Root mehr möglich, und jedes Gerät mit importiertem Root-Vertrauen muss die neue CA neu importieren).

```bash
# Schnelles Backup
tar czf home-ca-data-backup-$(date +%Y%m%d).tar.gz -C .. home-ca-data
```

## Sicherheitshinweise (bitte lesen)

- **Kein TLS eingebaut.** Der Container liefert reines HTTP. Für den Zugriff übers Heimnetz per Reverse-Proxy (z. B. Caddy, nginx, Traefik) mit TLS davorschalten, sonst geht das Admin-Passwort im Klartext übers Netz. Der OCSP-Port (2560) ist laut Standard ohnehin Klartext-HTTP, das ist normal.
- **Serverseitig erzeugte private Schlüssel** landen (verschlüsselt per HTTPS-Transport, aber unverschlüsselt in der DB) in `certificates.private_key_pem`, bis sie über den "Schlüssel löschen"-Weg entfernt werden. Für sensible Zertifikate CSR lieber lokal erzeugen und nur die CSR-Datei einreichen (Tab "CSR einreichen") — dann verlässt der private Schlüssel nie den Client.
- **NPM-Zugangsdaten** liegen ebenfalls unverschlüsselt in der DB (`npm_config`), gleiche Risikoklasse wie oben. Eigenes NPM-Nutzerkonto mit möglichst eingeschränkten Rechten empfehlenswert statt Admin-Zugang.
- Ein einzelnes Admin-Passwort für alle — kein Mehrbenutzer-Audit-Trail.

## Manuell testen (ohne Docker)

```bash
DATA_DIR=/tmp/ca-data ADMIN_PASSWORD=test php -S 0.0.0.0:8080 -t public
```

Für einen lokalen OCSP-Test zusätzlich manuell:

```bash
openssl ocsp -index /tmp/ca-data/ca/index.txt -CA /tmp/ca-data/ca/ca.crt \
  -rsigner /tmp/ca-data/ca/ca.crt -rkey /tmp/ca-data/ca/ca.key -port 2560
```

## Aufbau

```
public/index.html, assets/app.js, assets/style.css  – Frontend (SPA, kein Build-Schritt)
public/api.php                                       – einziger API-Einstiegspunkt
src/CaManager.php                                     – alle OpenSSL-Operationen (CA, CSR, CRL, OCSP-Index)
src/Database.php, Auth.php, helpers.php               – SQLite-Schema, Session-Auth, JSON-Helfer
entrypoint.sh                                         – startet PHP-Server + OCSP-Responder-Supervisor
```
