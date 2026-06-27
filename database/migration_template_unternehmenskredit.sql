-- Migration: Vorlage "Auskunftsbogen – Unternehmenskredit" (PSB, bank_id=1)
-- Datum: 2026-03-07

SET @body = 'Pacific Standard Bank
Auskunftsbogen – Unternehmenskredit

Aktenzeichen: {AKTENZEICHEN}
Datum der Anfrage: {DATUM}

Dieser Auskunftsbogen dient der Prüfung eines möglichen Unternehmenskredites durch die Pacific Standard Bank.
Alle Angaben sind wahrheitsgemäß zu machen. Falschangaben können zur Ablehnung oder Kündigung eines Kredites führen.

─────────────────────────────────────────────────
1. Unternehmensdaten
─────────────────────────────────────────────────

Name des Unternehmens:
{UNTERNEHMEN}

Branche / Tätigkeit:
{BRANCHE}

Gründungsdatum:
{GRUENDUNG}

Aktive Mitarbeiterzahl:
{MITARBEITERZAHL}

─────────────────────────────────────────────────
2. Ansprechpartner / Geschäftsführung
─────────────────────────────────────────────────

Name des Geschäftsführers / CEO:
{NAME}

Geburtsdatum:
{GEBURTSDATUM}

Telefonnummer:
{TELEFON}

E-Mail:
{EMAIL}

Position im Unternehmen:
{POSITION}

─────────────────────────────────────────────────
3. Kreditbedarf
─────────────────────────────────────────────────

Beantragter Kreditbetrag:
{BETRAG} $

Gewünschte Laufzeit:
{LAUFZEIT}

Geplanter Verwendungszweck:

{INVESTITION_1}

{INVESTITION_2}

{INVESTITION_3}

Geplanter Zeitpunkt der Investition:
{ZEITPUNKT}

─────────────────────────────────────────────────
4. Finanzielle Situation des Unternehmens
─────────────────────────────────────────────────

Geschätzter Unternehmenswert:
{UNTERNEHMENSWERT} $

Aktuelles Firmenvermögen:
{VERMOEGEN} $

Liquide Mittel (Bankguthaben):
{BANKGUTHABEN} $

Durchschnittlicher Wochenumsatz:
{WOCHENUMSATZ} $

Durchschnittlicher Wochengewinn:
{WOCHENGEWINN} $

─────────────────────────────────────────────────
5. Bestehende Verbindlichkeiten
─────────────────────────────────────────────────

Bestehen derzeit weitere Kredite oder finanzielle Verpflichtungen?

[ ] Nein
[ ] Ja

Falls ja:

Gläubiger: {GLAEUBIGER}
Restschuld: {RESTSCHULD} $
Wöchentliche Rate: {RATE} $

─────────────────────────────────────────────────
6. Eigenkapital
─────────────────────────────────────────────────

Eigenkapital für die geplante Investition:

{EIGENKAPITAL} $

─────────────────────────────────────────────────
7. Sicherheiten
─────────────────────────────────────────────────

Welche Sicherheiten können gestellt werden?

[ ] Unternehmensfahrzeuge
[ ] Immobilien / Gebäude
[ ] Unternehmensanteile
[ ] Persönliche Bürgschaft
[ ] Sonstige Sicherheiten

Beschreibung der Sicherheiten:

{SICHERHEITEN}

─────────────────────────────────────────────────
8. Persönliche Haftung
─────────────────────────────────────────────────

Der Geschäftsführer / Inhaber erklärt sich bereit, für den Unternehmenskredit persönlich zu haften.

[ ] Ja
[ ] Nein

─────────────────────────────────────────────────
9. Erklärung
─────────────────────────────────────────────────

Ich bestätige, dass die gemachten Angaben vollständig und wahrheitsgemäß sind.

Mir ist bekannt, dass falsche Angaben zur Ablehnung oder Kündigung eines Kredites führen können.




Ort / Datum                              Unterschrift Geschäftsführer / Antragsteller';

INSERT INTO templates (bank_id, name, type, subject, body, placeholders, is_active, version, created_by)
VALUES (
    1,
    'Auskunftsbogen – Unternehmenskredit',
    'OTHER',
    'Auskunftsbogen Unternehmenskredit – Aktenzeichen {AKTENZEICHEN}',
    @body,
    '["AKTENZEICHEN","DATUM","UNTERNEHMEN","BRANCHE","GRUENDUNG","MITARBEITERZAHL","NAME","GEBURTSDATUM","TELEFON","EMAIL","POSITION","BETRAG","LAUFZEIT","INVESTITION_1","INVESTITION_2","INVESTITION_3","ZEITPUNKT","UNTERNEHMENSWERT","VERMOEGEN","BANKGUTHABEN","WOCHENUMSATZ","WOCHENGEWINN","GLAEUBIGER","RESTSCHULD","RATE","EIGENKAPITAL","SICHERHEITEN"]',
    1,
    1,
    1
);
