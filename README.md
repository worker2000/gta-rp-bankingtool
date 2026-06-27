# PSB Kreditverwaltung

Ein webbasiertes Kreditverwaltungssystem für GTA RP-Banken. Mehrere Banken können parallel betrieben werden, jede mit eigenem Personal, eigenen Kreditnehmern und eigenen Konditionen.

> **Lizenz erforderlich** — Kostenlose Registrierung unter [flessinglabs.com](https://flessinglabs.com)

---

## Features

- **Multi-Bank-Betrieb** — beliebig viele Banken, jede mit eigenem Team und eigenen Policies
- **Kreditvergabe** — Auto-, Privat- und Geschäftskredite mit konfigurierbaren Zinsen, Laufzeiten und Eigenkapitalanforderungen
- **Kontoauszug-Import** — CSV-Import mit automatischer Zahlungszuordnung
- **Mahnwesen** — mehrstufiges Mahnverfahren mit konfigurierbaren Fristen und Gebühren
- **Kreditauskunft** — SCHUFA-ähnliche Bonitätsprüfung pro Kreditnehmer
- **Versicherungen** — Krankenversicherungen inkl. Schadensregulierung
- **Schließfächer** — Vermietung von Bankschließfächern
- **Rollenbasiertes Rechtesystem** — 6 Rollen von Support bis Direktor
- **Per-Bank-Policies** — alle Konditionen individuell je Bank konfigurierbar
- **Lizenzierung** — integriertes Licensing-System via FlessingLabs

---

## Voraussetzungen

- PHP 8.1+
- MySQL / MariaDB
- Apache mit `mod_rewrite`

---

## Installation

**1. Dateien kopieren**

Repository in dein Webserver-Verzeichnis klonen, z. B. nach `/var/www/html/psb/`.

**2. Datenbank anlegen**

```sql
CREATE DATABASE psbbank CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'psbbank'@'localhost' IDENTIFIED BY 'DEIN_PASSWORT';
GRANT ALL PRIVILEGES ON psbbank.* TO 'psbbank'@'localhost';
```

Schema importieren:

```bash
mysql -u psbbank -p psbbank < database/schema.sql
```

**3. Konfiguration anlegen**

```bash
cp config/database.example.php config/database.php
```

`config/database.php` öffnen und Datenbankzugangsdaten sowie `APP_URL` eintragen.

**4. Verzeichnisse vorbereiten**

```bash
mkdir -p storage uploads/documents logs
chown www-data:www-data storage uploads logs
```

**5. Lizenz eintragen**

Kostenlose Lizenz auf [flessinglabs.com](https://flessinglabs.com) holen, dann:

→ `http://deine-domain/psb/pages/admin/login.php` aufrufen  
→ Mit Superadmin einloggen (Standard: `superadmin` / `gta-banking`)  
→ Tab **Lizenz** → Schlüssel eintragen & aktivieren

---

## Erster Start

Nach der Installation:

1. Admin-Login unter `/pages/admin/login.php`
   - Benutzername: `superadmin`
   - Passwort: `gta-banking` (muss beim ersten Login geändert werden)
2. **Admin → Banken** — erste Bank anlegen
3. **Admin → Benutzer** — Mitarbeiter anlegen und Bank zuweisen
4. Mitarbeiter loggen sich über die Hauptseite `/` ein

---

## Rollen

| Rolle | Berechtigungen |
|---|---|
| **Direktor** | Vollzugriff inkl. Einstellungen |
| **Senior Kreditbearbeiter** | Kredite erstellen & freigeben, Import, Mahnwesen |
| **Kreditbearbeiter** | Kredite erstellen & bearbeiten, Import |
| **Inkasso** | Mahnungen erstellen & verwalten |
| **Prüfer** | Nur lesen + Berichte |
| **Support** | Nur lesen |

---

## Einstellungen (pro Bank)

Alle Werte sind per Bank konfigurierbar unter **Einstellungen**:

- Zinssätze pro Kredittyp & Bearbeitungsgebühr
- Min./Max. Laufzeiten pro Kredittyp
- Kreditgrenzen, Kompetenzgrenze, max. aktive Kredite pro Kunde
- Mahnfristen, Mahngebühren, Verzugszinssatz

---

## Superadmin-Passwort zurücksetzen

In `config/database.php`:

```php
define('RESET_SUPERADMIN_PASSWORD', true);
```

Seite aufrufen → Passwort wird auf `gta-banking` zurückgesetzt → Flag danach wieder auf `false` setzen.

---

## Lizenzierung

Dieses Tool nutzt das Licensing-System von [FlessingLabs](https://flessinglabs.com). Eine kostenlose Lizenz für den Eigengebrauch kann dort direkt bezogen werden. Der Lizenzschlüssel wird im Admin-Panel unter **Lizenz** eingetragen.

---

## Support & Community

- **Bug melden / Feature anfragen** → [Issues](https://github.com/worker2000/gta-rp-bankingtool/issues)
- **Fragen & Diskussionen** → [Discussions](https://github.com/worker2000/gta-rp-bankingtool/discussions)

---

*Developed by [FlessingLabs](https://flessinglabs.com)*
