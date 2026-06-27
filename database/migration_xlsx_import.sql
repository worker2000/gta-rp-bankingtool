-- =============================================
-- Fortis Finance XLSX Import
-- Generiert: 2026-03-06 01:12:12
-- =============================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- =============================================
-- PART 1: Bestehende Borrower updaten
-- =============================================

UPDATE borrowers SET phone = '555381817', email = 'lucasdemarino@mail.ls', date_of_birth = '1990-10-18', bank_account_iban = 'STD24827494', employer = 'Fortis Finance', notes = CONCAT(COALESCE(notes,''), ' | FF-0001') WHERE id = 17;
UPDATE borrowers SET phone = '555729257', email = 'agent_H@mail.ls', date_of_birth = '1986-04-24', bank_account_iban = 'PSB61618296', employer = 'Arbeitslos', notes = CONCAT(COALESCE(notes,''), ' | FF-0006') WHERE id = 12;
UPDATE borrowers SET phone = '555 227327', email = 'dan.adams@mail.ls', date_of_birth = '2000-07-14', bank_account_iban = 'SIL38630023', employer = 'Element Bar', notes = CONCAT(COALESCE(notes,''), ' | FF-0008') WHERE id = 47;
UPDATE borrowers SET phone = '555489631', email = 'butchmc@mail.ls', date_of_birth = '1983-10-02', bank_account_iban = 'BRZ26333761', employer = 'Craftbar', notes = CONCAT(COALESCE(notes,''), ' | FF-0009') WHERE id = 48;
UPDATE borrowers SET phone = '555574070', email = 'willy.watermore@mail.ls', date_of_birth = '1983-03-15', bank_account_iban = 'SIL15302960', employer = 'SAFRD', notes = CONCAT(COALESCE(notes,''), ' | FF-0010') WHERE id = 15;
UPDATE borrowers SET phone = '555928224', email = 'poppyparker@mail.ls', date_of_birth = '1997-01-17', bank_account_iban = 'BRZ61087654', employer = 'Tankstelle Mirror Park', notes = CONCAT(COALESCE(notes,''), ' | FF-0011') WHERE id = 10;
UPDATE borrowers SET phone = '555364292', email = 'l.king@mail.ls', date_of_birth = '2007-09-22', bank_account_iban = 'SIL70262300', employer = 'LSR', notes = CONCAT(COALESCE(notes,''), ' | FF-0013') WHERE id = 29;
UPDATE borrowers SET phone = '555 823973', email = 'julio.r.g@mail.ls', date_of_birth = '2006-06-05', bank_account_iban = 'BRZ45122152', employer = 'Fortis Finance', notes = CONCAT(COALESCE(notes,''), ' | FF-0014') WHERE id = 13;
UPDATE borrowers SET phone = '555 549811', email = 'tariq.k@mail.ls', date_of_birth = '2004-06-07', bank_account_iban = 'PSB86774507', notes = CONCAT(COALESCE(notes,''), ' | FF-0015') WHERE id = 16;
UPDATE borrowers SET phone = '555 657769', email = 'dean.ryan@mail.ls', date_of_birth = '1982-06-25', bank_account_iban = 'BRZ23953098', employer = 'LSFD', notes = CONCAT(COALESCE(notes,''), ' | FF-0018') WHERE id = 20;
UPDATE borrowers SET phone = '555857937', email = 'christophmiller@mail.ls', date_of_birth = '2000-07-20', bank_account_iban = 'BRZ52808710', employer = 'Devil''s Garage', notes = CONCAT(COALESCE(notes,''), ' | FF-0021') WHERE id = 26;
UPDATE borrowers SET phone = '555626313', email = 'kathi2000@mail.ls', date_of_birth = '2000-06-30', bank_account_iban = 'PS2L77358262', employer = 'SAFRD', notes = CONCAT(COALESCE(notes,''), ' | FF-0027') WHERE id = 19;
UPDATE borrowers SET phone = '555557792', email = 'tora.tanaka@mail.ls', date_of_birth = '1997-05-02', bank_account_iban = 'BRZ69378094', employer = 'Gabs Store', notes = CONCAT(COALESCE(notes,''), ' | FF-0028') WHERE id = 42;
UPDATE borrowers SET phone = '555178766', email = 'kenji.kuro@mail.ls', date_of_birth = '1997-01-31', bank_account_iban = 'PSB09667192', employer = 'Gabs Store', notes = CONCAT(COALESCE(notes,''), ' | FF-0029') WHERE id = 22;
UPDATE borrowers SET phone = '555438398', email = 'haru.kazuma@mail.ls', date_of_birth = '2000-03-12', bank_account_iban = 'BRZ02764280', employer = 'Gabs Store', notes = CONCAT(COALESCE(notes,''), ' | FF-0030') WHERE id = 43;
UPDATE borrowers SET phone = '555339646', email = 'Frankmccormick@mail.ls', date_of_birth = '1998-06-01', bank_account_iban = 'BRZ75525030', employer = 'Gabs Store', notes = CONCAT(COALESCE(notes,''), ' | FF-0031') WHERE id = 49;
UPDATE borrowers SET phone = '555139277', email = 'dave_sander@mail.ls', date_of_birth = '1996-10-26', bank_account_iban = 'BRZ99495053', employer = 'DPA', notes = CONCAT(COALESCE(notes,''), ' | FF-0035') WHERE id = 27;
UPDATE borrowers SET phone = '555043366', email = 'lferroni@mail.ls', date_of_birth = '1994-02-21', bank_account_iban = 'BRZ50829982', employer = 'Arbeitssuchend', notes = CONCAT(COALESCE(notes,''), ' | FF-0036') WHERE id = 32;
UPDATE borrowers SET phone = '555970031', date_of_birth = '1996-06-13', bank_account_iban = 'PSB51192648', employer = 'Bennys', notes = CONCAT(COALESCE(notes,''), ' | FF-0039') WHERE id = 36;
UPDATE borrowers SET phone = '555489937', email = 'biker@mail.ls', date_of_birth = '2002-08-28', bank_account_iban = 'BRZ63673408', employer = 'Mosley''s', notes = CONCAT(COALESCE(notes,''), ' | FF-0040') WHERE id = 45;
UPDATE borrowers SET phone = '555125758', email = 'antony.ravenmoor@mail.ls', date_of_birth = '1980-01-26', bank_account_iban = 'SIL02308989', employer = 'DPA', notes = CONCAT(COALESCE(notes,''), ' | FF-0041') WHERE id = 8;
UPDATE borrowers SET phone = '555801086', email = 'max.calaway@mail.ls', date_of_birth = '1995-11-13', bank_account_iban = 'PSB20885884', employer = 'DPA', notes = CONCAT(COALESCE(notes,''), ' | FF-0043') WHERE id = 28;
UPDATE borrowers SET phone = '555364966', email = 'lincolnbrown@mail.ls', date_of_birth = '1994-03-09', bank_account_iban = 'PSG63713839', employer = 'LSR', notes = CONCAT(COALESCE(notes,''), ' | FF-0044') WHERE id = 31;
UPDATE borrowers SET phone = '555177220', email = 'jayden.tyrese.mcgray@mail.ls', date_of_birth = '1998-01-24', bank_account_iban = 'PSS67569080', employer = 'LSR & LST', notes = CONCAT(COALESCE(notes,''), ' | FF-0045') WHERE id = 25;
UPDATE borrowers SET phone = '555116057', email = 'mateo1990@mail.ls', date_of_birth = '1990-08-15', bank_account_iban = 'PSB02642212', employer = 'LSR', notes = CONCAT(COALESCE(notes,''), ' | FF-0046') WHERE id = 41;
UPDATE borrowers SET phone = '555987909', email = 'rob.itmanson@mail.ls', date_of_birth = '1986-08-02', bank_account_iban = 'BRZ58321696', employer = 'DPA', notes = CONCAT(COALESCE(notes,''), ' | FF-0052') WHERE id = 38;
UPDATE borrowers SET phone = '555899864', email = 'driusbends@mail.ls', date_of_birth = '2005-07-30', bank_account_iban = 'BRZ36579675', employer = 'Arbeitssuchend', notes = CONCAT(COALESCE(notes,''), ' | FF-0053') WHERE id = 18;
UPDATE borrowers SET phone = '555731363', email = 'amayamoreno@mail.ls', date_of_birth = '2004-02-05', bank_account_iban = 'PSB45591905', employer = 'Arbeitssuchend', notes = CONCAT(COALESCE(notes,''), ' | FF-0054') WHERE id = 34;
UPDATE borrowers SET phone = '555294630', email = 'mikemoor@mail.ls', date_of_birth = '1955-05-08', bank_account_iban = 'BRZ31229480', employer = 'LST', notes = CONCAT(COALESCE(notes,''), ' | FF-0056') WHERE id = 40;
UPDATE borrowers SET phone = '555664386', email = 'reynolds@mail.ls', date_of_birth = '1989-06-15', bank_account_iban = 'GOLD23334417', employer = 'LST', notes = CONCAT(COALESCE(notes,''), ' | FF-0057') WHERE id = 21;
UPDATE borrowers SET phone = '555217773', email = 'matteo.suarez@mail.ls', date_of_birth = '1996-07-31', bank_account_iban = 'BRZ93448136', employer = 'Devils Garage', notes = CONCAT(COALESCE(notes,''), ' | FF-0059') WHERE id = 23;
UPDATE borrowers SET phone = '555413459', email = 'kailanihale@mail.ls', date_of_birth = '2000-11-01', bank_account_iban = 'SIL60732783', employer = 'LSPD', notes = CONCAT(COALESCE(notes,''), ' | FF-0060') WHERE id = 39;
UPDATE borrowers SET phone = '555868639', email = 'ana.cortes@mail.ls', date_of_birth = '2000-04-10', bank_account_iban = 'BRZ14235400', employer = 'Herr Kutz', notes = CONCAT(COALESCE(notes,''), ' | FF-0061') WHERE id = 37;
UPDATE borrowers SET phone = '555431823', email = 'maeve@mail.ls', date_of_birth = '1989-05-17', bank_account_iban = 'PSB56926064', employer = 'Weazle News', notes = CONCAT(COALESCE(notes,''), ' | FF-0062') WHERE id = 46;
UPDATE borrowers SET phone = '555259107', email = 'natecavenking@mail.ls', date_of_birth = '1997-01-07', bank_account_iban = 'PSB96627891', employer = 'Anwalt', notes = CONCAT(COALESCE(notes,''), ' | FF-0063') WHERE id = 44;
-- 35 Borrower geupdated

-- =============================================
-- PART 2: Fehlende Kunden anlegen
-- =============================================

INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00043', 'Tatum', 'Madrazo', '1988-07-27', '555605212', 'fox@mail.ls', 'Arbeitslos', NULL, 'PSB64308198', 'Orig-KdNr: FF-0005', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00044', 'Devone', 'Banks', '2003-02-25', '555208605', 'crown@mail.ls', 'Arbeitslos', NULL, 'BRZ17477674', 'Orig-KdNr: FF-0016', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00045', 'Renji', 'Takahaski', '2000-05-12', '555005286', 'renjit@mail.ls', 'Inhaber von Benny''s', NULL, 'BRZ23953098', 'Orig-KdNr: FF-0017', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00046', 'Nikita', 'De Jong', '1990-11-27', '555 710689', 'info@secureunit.ls', 'Secure Unit', NULL, 'PSB35118305', 'Orig-KdNr: FF-0020', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00047', 'Daniel', 'Branston', '1985-06-11', '555049006', 'dbranston@mail.ls', 'SAFRD, McGill&Olsen', NULL, 'GOLD27562456', 'Orig-KdNr: FF-0022', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00048', 'Tori', 'Julyn', '1994-05-30', '555150166', 'tori.juyln@mail.ls', 'Los Santos Medical Department', NULL, 'PSG49112294', 'Orig-KdNr: FF-0024', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00049', 'Sarah', 'Tyler', '1993-12-02', '555118155', 'sarah.t@mail.ls', 'Los Santos Medical Department', NULL, 'GOLD99826483', 'Orig-KdNr: FF-0025', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00050', 'Helena', 'Rowley', NULL, '555841455', 'helenaarowley@mail.ls', 'Selbstständiger Anwalt', NULL, 'BRZ62080552', 'Orig-KdNr: FF-0026', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00051', 'Mireia', 'Vidal', '1994-08-08', '555546930', 'mireia.vidal@mail.ls', 'Gabs Store', NULL, 'PSB729772273', 'Orig-KdNr: FF-0032', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00052', 'Tiffany Misty', 'Conner', '1994-11-02', '555448268', 'tiffy@mail.ls', 'Arbeitslos', NULL, 'BRZ60792074', 'Orig-KdNr: FF-0033', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00053', 'Aden', 'Conner', '1987-11-21', '555751382', 'AdenConner@mail.ls', 'Opofuel', NULL, 'BRZ50391670', 'Orig-KdNr: FF-0034', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00054', 'Victoria Samantha', 'Walker', NULL, '555827626', 'vic@mail.ls', 'Arbeitssuchend', NULL, 'BRZ06779420', 'Orig-KdNr: FF-0047', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00055', 'Charles', 'McBride', '2025-12-18', '555985230', 'mcbride@mail.ls', 'DAO', NULL, 'BRZ55968295', 'Orig-KdNr: FF-0048', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00056', 'Ajuna Luana', 'Madrazo', '1994-07-06', '555 773084', 'ajunaluana@mail.ls', 'Casa Del Fuego', NULL, 'PSB20837069', 'Orig-KdNr: FF-0049', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00057', 'Reno', 'Moreau', '2000-05-06', '555447745', 'moreau@mail.ls', 'LSPD', NULL, 'BRZ18903470', 'Orig-KdNr: FF-0055', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00058', 'Ethan', 'Carter', '1980-03-12', '555396382', 'ethan_carter@mail.ls', 'LSPD', NULL, 'BRZ21311341', 'Orig-KdNr: FF-0058', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00059', 'Fortis', 'Finance', NULL, '40715', 'Managment@fortisfinance.ls', NULL, 'Fortis Finance', NULL, 'Orig-KdNr: FF-0003', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00060', 'San Andreas', 'Fire & Rescue Department', NULL, '931', 'Leitung@safrd.gov', 'Staat San Andreas', 'San Andreas Fire & Rescue Department', 'SABB28252583', 'Orig-KdNr: FF-0004', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00061', 'District Attorneys', 'Office', NULL, 'folgt', 'administration@dao.gov', 'Staat San Andreas', 'District Attorneys Office', 'SABB25044604', 'Orig-KdNr: FF-0007', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00062', 'Department of', 'Public Administration', NULL, '940', 'sozialversicherung@dpa.gov', 'Staat San Andreas', 'Department of Public Administration', 'SABB84249758', 'Orig-KdNr: FF-0012', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00063', 'Gruppe', 'Sechs', NULL, '66001', 'info@gruppe6.ls', NULL, 'Gruppe Sechs', 'Firma32566058', 'Orig-KdNr: FF-0019', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00064', 'Gabs', 'Store', NULL, '75057', 'info@gabs.ls', NULL, 'Gabs Store', 'Firma79600338', 'Orig-KdNr: FF-0023', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00065', 'Pacific Standard', 'Bank', NULL, '77097', 'eob@psb.ls', NULL, 'Pacific Standard Bank', NULL, 'Orig-KdNr: FF-0037', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00066', 'Al', 'Dente', NULL, '33683', 'kontakt@aldente.ls', NULL, 'Al Dente', NULL, 'Orig-KdNr: FF-0038', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00067', 'Los Santos', 'Transit', NULL, '33333', 'reynolds@lst.ls', 'Staat San Andreas', 'Los Santos Transit', 'PS2B37172890', 'Orig-KdNr: FF-0042', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00068', 'Casa', 'del Fuego', NULL, NULL, 'info@casa-del-fuego.ls', NULL, 'Casa del Fuego', 'PS2B14576022', 'Orig-KdNr: FF-0050', 1);
INSERT INTO borrowers (bank_id, customer_number, first_name, last_name, date_of_birth, phone, email, employer, company, bank_account_iban, notes, is_active)
  VALUES (2, 'FF-2026-00069', 'Mirrors', 'Drive', NULL, '24007', 'info@mirrorsdrive.ls', NULL, 'Mirrors Drive', 'PS2B56054569', 'Orig-KdNr: FF-0051', 1);
-- 27 neue Borrower angelegt

-- =============================================
-- PART 3: Insurance Employers
-- =============================================

INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Fortis Finance', '40715', 'Managment@fortisfinance.ls', NULL, 1, 'Orig-KdNr: FF-0003');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'San Andreas Fire & Rescue Department', '931', 'Leitung@safrd.gov', 'SABB28252583', 1, 'Orig-KdNr: FF-0004');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'District Attorneys Office', 'folgt', 'administration@dao.gov', 'SABB25044604', 1, 'Orig-KdNr: FF-0007');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Department of Public Administration', '940', 'sozialversicherung@dpa.gov', 'SABB84249758', 1, 'Orig-KdNr: FF-0012');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Gabs Store', '75057', 'info@gabs.ls', 'Firma79600338', 1, 'Orig-KdNr: FF-0023');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Gruppe Sechs', '66001', 'info@gruppe6.ls', 'Firma32566058', 1, 'Orig-KdNr: FF-0019');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Al Dente', '33683', 'kontakt@aldente.ls', NULL, 1, 'Orig-KdNr: FF-0038');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Los Santos Transit', '33333', 'reynolds@lst.ls', 'PS2B37172890', 1, 'Orig-KdNr: FF-0042');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Casa del Fuego', NULL, 'info@casa-del-fuego.ls', 'PS2B14576022', 1, 'Orig-KdNr: FF-0050');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Mirrors Drive', '24007', 'info@mirrorsdrive.ls', 'PS2B56054569', 1, 'Orig-KdNr: FF-0051');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Taco Farmer', NULL, NULL, NULL, 1, 'Orig-KdNr: FF-0061');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'OpoFuel Tankstelle', NULL, NULL, NULL, 1, 'Orig-KdNr: FF-0062');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Mosleys Cars', NULL, NULL, NULL, 1, 'Orig-KdNr: FF-0063');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Golden Loaf Bakery', NULL, NULL, NULL, 1, 'Orig-KdNr: FF-0064');
INSERT INTO insurance_employers (bank_id, company_name, phone, email, iban, is_active, notes)
  VALUES (2, 'Weazel News', NULL, NULL, NULL, 1, 'Orig-KdNr: FF-0065');
-- 15 Employers angelegt

-- =============================================
-- PART 4: Gruppenvertraege
-- =============================================

INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Fortis Finance' ORDER BY id DESC LIMIT 1),
    '31102025LdM01', 1, '2025-10-31', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='San Andreas Fire & Rescue Department' ORDER BY id DESC LIMIT 1),
    '08112025LdM01', 3, '2025-11-08', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 3', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='District Attorneys Office' ORDER BY id DESC LIMIT 1),
    '09112025LdM01', 2, '2025-11-09', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 2', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Department of Public Administration' ORDER BY id DESC LIMIT 1),
    '21112025LdM01', 2, '2025-11-22', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 2', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Gabs Store' ORDER BY id DESC LIMIT 1),
    '07122025LdM02', 1, '2025-12-08', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Gruppe Sechs' ORDER BY id DESC LIMIT 1),
    '27112025LdM01', 3, '2025-12-11', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 3', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Al Dente' ORDER BY id DESC LIMIT 1),
    '08122025LdM03', 1, '2025-12-19', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Los Santos Transit' ORDER BY id DESC LIMIT 1),
    '19122025LdM01', 2, '2025-12-22', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 2', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Casa del Fuego' ORDER BY id DESC LIMIT 1),
    '09122025LdM01', 1, '2026-01-02', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Mirrors Drive' ORDER BY id DESC LIMIT 1),
    '22122025LdM03', 1, '2026-01-02', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Taco Farmer' ORDER BY id DESC LIMIT 1),
    '14012026RW001', 1, '2026-01-15', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='OpoFuel Tankstelle' ORDER BY id DESC LIMIT 1),
    '15012026WR001', 1, '2026-01-15', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Mosleys Cars' ORDER BY id DESC LIMIT 1),
    '16012026RW01', 1, '2026-01-17', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Golden Loaf Bakery' ORDER BY id DESC LIMIT 1),
    '13012026RW01', 1, '2026-01-19', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_group_contracts (bank_id, employer_id, contract_number, product_id, start_date, status, notes, created_by)
  VALUES (2,
    (SELECT id FROM insurance_employers WHERE bank_id=2 AND company_name='Weazel News' ORDER BY id DESC LIMIT 1),
    '26012026RW01', 2, '2026-01-27', 'ACTIVE',
    'Import GSheets | Versicherung Klasse 2', 2);
-- 15 Gruppenvertraege angelegt

-- =============================================
-- PART 5: Privatversicherungen
-- =============================================

INSERT INTO insurance_contracts (bank_id, contract_number, borrower_id, product_id, insured_first_name, insured_last_name, start_date, premium_amount, payment_interval, status, notes, created_by)
  VALUES (2, '08122025LdM05', (SELECT id FROM borrowers WHERE bank_id=2 AND first_name='Tori' AND last_name='Julyn' LIMIT 1), 2, 'Tori', 'Julyn', '2025-12-08', 250.00, 'MONTHLY', 'ACTIVE', 'Import GSheets | Versicherung Klasse 2', 2);
INSERT INTO insurance_contracts (bank_id, contract_number, borrower_id, product_id, insured_first_name, insured_last_name, start_date, premium_amount, payment_interval, status, notes, created_by)
  VALUES (2, '08122025LdM06', (SELECT id FROM borrowers WHERE bank_id=2 AND first_name='Sarah' AND last_name='Tyler' LIMIT 1), 2, 'Sarah', 'Tyler', '2025-12-08', 250.00, 'MONTHLY', 'ACTIVE', 'Import GSheets | Versicherung Klasse 2', 2);
INSERT INTO insurance_contracts (bank_id, contract_number, borrower_id, product_id, insured_first_name, insured_last_name, start_date, premium_amount, payment_interval, status, notes, created_by)
  VALUES (2, '08122025LdM04', (SELECT id FROM borrowers WHERE bank_id=2 AND first_name='Helena' AND last_name='Rowley' LIMIT 1), 2, 'Helena', 'Rowley', '2025-12-09', 250.00, 'MONTHLY', 'ACTIVE', 'Import GSheets | Versicherung Klasse 2', 2);
INSERT INTO insurance_contracts (bank_id, contract_number, borrower_id, product_id, insured_first_name, insured_last_name, start_date, premium_amount, payment_interval, status, notes, created_by)
  VALUES (2, '11122025LdM02', (SELECT id FROM borrowers WHERE bank_id=2 AND first_name='Tiffany Misty' AND last_name='Conner' LIMIT 1), 1, 'Tiffany Misty', 'Conner', '2025-12-11', 150.00, 'MONTHLY', 'ACTIVE', 'Import GSheets | Versicherung Klasse 1', 2);
INSERT INTO insurance_contracts (bank_id, contract_number, borrower_id, product_id, insured_first_name, insured_last_name, start_date, premium_amount, payment_interval, status, notes, created_by)
  VALUES (2, '11122025LdM01', (SELECT id FROM borrowers WHERE bank_id=2 AND first_name='Aden' AND last_name='Conner' LIMIT 1), 1, 'Aden', 'Conner', '2025-12-11', 150.00, 'MONTHLY', 'ACTIVE', 'Import GSheets | Versicherung Klasse 1', 2);
-- 5 Privatversicherungen angelegt

-- =============================================
-- PART 6: Leistungsarchiv -> insurance_claims
-- =============================================

INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Daniel' AND insured_last_name='Branston' LIMIT 1),
    'FF-LS-2025-00001', '2025-11-23', 'DOCTOR', 'MD-111', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW47/2025 | Lucas de Marino', '2025-11-26');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Dean' AND insured_last_name='Ryan' LIMIT 1),
    'FF-LS-2025-00002', '2025-11-23', 'DOCTOR', 'MD-75', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW47/2025 | Lucas de Marino', '2025-11-26');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Kathleen' AND insured_last_name='Wolf' LIMIT 1),
    'FF-LS-2025-00003', '2025-11-23', 'DOCTOR', 'MD-129', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW47/2025 | Lucas de Marino', '2025-11-26');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Ryan' AND insured_last_name='Kingston' LIMIT 1),
    'FF-LS-2025-00004', '2025-11-23', 'DOCTOR', 'MD-134', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW47/2025 | Lucas de Marino', '2025-11-26');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Daniel' AND insured_last_name='Branston' LIMIT 1),
    'FF-LS-2025-00005', '2025-11-23', 'DOCTOR', 'MD-140', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW47/2025 | Lucas de Marino', '2025-11-26');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Evelyn' AND insured_last_name='Willard-Bright' LIMIT 1),
    'FF-LS-2025-00006', '2025-11-24', 'DOCTOR', 'MD-179', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW48/2025 | Julio Ramírez-González', '2025-12-03');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Kathleen' AND insured_last_name='Wolf' LIMIT 1),
    'FF-LS-2025-00007', '2025-11-24', 'DOCTOR', 'MD-176', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW48/2025 | Julio Ramírez-González', '2025-12-03');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Daniel' AND insured_last_name='Branston' LIMIT 1),
    'FF-LS-2025-00008', '2025-11-26', 'DOCTOR', 'MD-198', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW48/2025 | Julio Ramírez-González', '2025-12-03');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Kathleen' AND insured_last_name='Wolf' LIMIT 1),
    'FF-LS-2025-00009', '2025-11-26', 'DOCTOR', 'MD-197', 350.00, 350.00, 350.00, 'PAID',
    'Import GSheets | KW48/2025 | Julio Ramírez-González', '2025-12-03');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Wessley' AND insured_last_name='Stockstone' LIMIT 1),
    'FF-LS-2025-00010', '2025-11-28', 'DOCTOR', 'MD-216', 350.00, 350.00, 350.00, 'PAID',
    'Import GSheets | KW48/2025 | Julio Ramírez-González', '2025-12-03');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Daniel' AND insured_last_name='Branston' LIMIT 1),
    'FF-LS-2025-00011', '2025-11-29', 'DOCTOR', 'MD-220', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW48/2025 | Julio Ramírez-González', '2025-12-03');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Daniel' AND insured_last_name='Branston' LIMIT 1),
    'FF-LS-2025-00012', '2025-12-06', 'DOCTOR', 'Yuna Jinx', 750.00, 750.00, 750.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Kathleen' AND insured_last_name='Wolf' LIMIT 1),
    'FF-LS-2025-00013', '2025-12-08', 'DOCTOR', 'Sarah Tyler', 350.00, 350.00, 350.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Kathleen' AND insured_last_name='Wolf' LIMIT 1),
    'FF-LS-2025-00014', '2025-12-08', 'DOCTOR', 'Sarah Tyler', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Steven' AND insured_last_name='Dean' LIMIT 1),
    'FF-LS-2025-00015', '2025-12-13', 'DOCTOR', 'Jesse Callahan', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Haru' AND insured_last_name='Kazuma' LIMIT 1),
    'FF-LS-2025-00016', '2025-12-14', 'DOCTOR', 'Sarah Tyler', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Ayane' AND insured_last_name='Tanaka' LIMIT 1),
    'FF-LS-2025-00017', '2025-12-14', 'DOCTOR', 'Sarah Tyler', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Mireia' AND insured_last_name='Vidal' LIMIT 1),
    'FF-LS-2025-00018', '2025-12-17', 'DOCTOR', 'Dominic Miller', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Ayane' AND insured_last_name='Tanaka' LIMIT 1),
    'FF-LS-2025-00019', '2025-12-19', 'DOCTOR', 'Jean Malign-Schiller', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Haru' AND insured_last_name='Kazuma' LIMIT 1),
    'FF-LS-2025-00020', '2025-12-19', 'DOCTOR', 'Dominic Miller', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Haru' AND insured_last_name='Kazuma' LIMIT 1),
    'FF-LS-2025-00021', '2025-12-20', 'DOCTOR', 'Isabel Catalina Cortez Reyes', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Mireia' AND insured_last_name='Vidal' LIMIT 1),
    'FF-LS-2025-00022', '2025-12-21', 'DOCTOR', 'Jean Malign-Schiller', 350.00, 350.00, 350.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Dean' AND insured_last_name='Ryan' LIMIT 1),
    'FF-LS-2025-00023', '2025-12-28', 'DOCTOR', 'Isabel Catalina Cortez Reyes', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Diosa' AND insured_last_name='Camila Castro' LIMIT 1),
    'FF-LS-2026-00024', '2026-01-02', 'DOCTOR', 'Jean Malign-Schiller', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Tori' AND insured_last_name='Julyn' LIMIT 1),
    'FF-LS-2026-00025', '2026-01-05', 'DOCTOR', 'Jean Malign-Schiller', 750.00, 750.00, 750.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Kathleen' AND insured_last_name='Wolf' LIMIT 1),
    'FF-LS-2026-00026', '2026-01-07', 'DOCTOR', 'Jesse Callahan', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Steven' AND insured_last_name='Dean' LIMIT 1),
    'FF-LS-2026-00027', '2026-01-07', 'DOCTOR', 'Jesse Callahan', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW2/2026 | Robin Walker', '2026-01-07');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Bonifatius' AND insured_last_name='Featherstonehaugh' LIMIT 1),
    'FF-LS-2026-00028', '2026-01-07', 'DOCTOR', 'Dominic Miller', 750.00, 750.00, 750.00, 'PAID',
    'Import GSheets | KW4/2026 | Robin Walker', '2026-01-20');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Tori' AND insured_last_name='Julyn' LIMIT 1),
    'FF-LS-2026-00029', '2026-01-08', 'DOCTOR', 'Isabel Catalina Cortez Reyes', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW4/2026 | Robin Walker', '2026-01-20');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Daniel' AND insured_last_name='Branston' LIMIT 1),
    'FF-LS-2026-00030', '2026-01-09', 'DOCTOR', 'Jean Malign-Schiller', 350.00, 350.00, 350.00, 'PAID',
    'Import GSheets | KW4/2026 | Robin Walker', '2026-01-20');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Mike' AND insured_last_name='Moor' LIMIT 1),
    'FF-LS-2026-00031', '2026-01-11', 'DOCTOR', 'Jean Malign-Schiller', 350.00, 350.00, 350.00, 'PAID',
    'Import GSheets | KW4/2026 | Robin Walker', '2026-01-20');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Diosa' AND insured_last_name='Camila Castro' LIMIT 1),
    'FF-LS-2026-00032', '2026-01-14', 'DOCTOR', 'Aaron Vale', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW4/2026 | Robin Walker', '2026-01-20');
INSERT INTO insurance_claims (bank_id, contract_id, claim_number, treatment_date, treatment_type, provider_name, billed_amount, covered_amount, payout_amount, status, reviewer_notes, created_at)
  VALUES (2,
    (SELECT id FROM insurance_contracts WHERE bank_id=2 AND insured_first_name='Steven' AND insured_last_name='Dean' LIMIT 1),
    'FF-LS-2026-00033', '2026-01-20', 'DOCTOR', 'Jean Malign-Schiller', 50.00, 50.00, 50.00, 'PAID',
    'Import GSheets | KW4/2026 | Robin Walker', '2026-01-20');
-- 33 Leistungsantraege importiert

-- =============================================
-- PART 7: Safeboxes-Tabelle
-- =============================================

CREATE TABLE IF NOT EXISTS safeboxes (
  id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bank_id tinyint unsigned NOT NULL DEFAULT 2,
  borrower_id int unsigned NULL,
  box_number varchar(50) NOT NULL,
  box_size enum('KLEIN','MITTEL','GROSS') NOT NULL DEFAULT 'KLEIN',
  iban varchar(34) NULL,
  weekly_fee decimal(8,2) NOT NULL DEFAULT 0.00,
  last_payment_date date NULL,
  status enum('ACTIVE','RELEASED') NOT NULL DEFAULT 'ACTIVE',
  staff_initials varchar(10) NULL,
  notes text NULL,
  released_at datetime NULL,
  released_by varchar(10) NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_bank_id (bank_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO safeboxes (bank_id, borrower_id, box_number, box_size, iban, weekly_fee, status, staff_initials, notes, released_at, released_by, created_at)
  VALUES (2, 17, 'Klein 1', 'KLEIN', 'STD24827494', 2500.00, 'RELEASED', 'MvS',
    'Import aus Google Sheets', '2025-11-03 00:00:00', 'LdM', '2025-11-02 18:05:32');

SET foreign_key_checks = 1;

-- IMPORT ABGESCHLOSSEN