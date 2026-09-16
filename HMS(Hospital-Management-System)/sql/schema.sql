-- ******************************************************************************
-- HMS — MySQL Schema  (aligned to ERD / FR-01 → FR-06 / NFR-1 → NFR-6)
-- Database : hms_db
-- Charset  : utf8mb4 / utf8mb4_unicode_ci
--
-- Tables:
--   Core  Entities     : users, patients, doctors, nurses, staff
--   Operations: appointments, prescriptions, billing_records, payments
--   HR/Shifts : staff_shifts
--   Inventory : inventory, stock_alerts, suppliers, purchase_orders, stock_receipts
--   Audit/Sync: audit_log, sync_log
--
-- Triggers (5):
--   trg_stock_receipt_update     — FR-05: update inventory StockLevel on receipt
--   trg_stock_reorder_alert      — FR-05: insert stock_alert when StockLevel < ReorderPoint
--   trg_billing_auto_paid        — FR-04: set PaymentStatus='Paid' when AccountBalance=0
--   trg_prescription_audit       — FR-02: write audit_log entry on prescription INSERT
--   trg_sync_log_default_status  — NFR-3: ensure SyncStatus defaults to 'Local'
-- ******************************************************************************

CREATE DATABASE IF NOT EXISTS hms_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hms_db;

-- users 
-- Central authentication table (all roles).  NFR-1 (POPIA/NHA/HPCSA).
CREATE TABLE IF NOT EXISTS users (
    user_id       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)      NOT NULL,
    password_hash VARCHAR(255)     NOT NULL,
    role          ENUM('SuperAdmin','Admin','Doctor','Nurse','Patient','Pharmacist') NOT NULL,
    email         VARCHAR(100)              DEFAULT NULL,
    full_name     VARCHAR(100)     NOT NULL DEFAULT '',
    is_active     TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email    (email)
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- patients
-- FR-01 / Table 19: PatientId, PatientName, PatientIdNumber, PatientContact,
--                   PatientMedicalNotes (encrypted clinical history).
CREATE TABLE IF NOT EXISTS patients (
    patient_id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id                   INT UNSIGNED           DEFAULT NULL,   -- nullable: walk-in patients
    first_name                VARCHAR(50)   NOT NULL,
    last_name                 VARCHAR(50)   NOT NULL,
    id_number                 VARCHAR(13)   NOT NULL,                -- Unique SA 13-digit ID (POPIA)
    date_of_birth             DATE          NOT NULL,
    gender                    ENUM('Male','Female','Other') NOT NULL,
    phone                     VARCHAR(20)            DEFAULT NULL,
    email                     VARCHAR(100)           DEFAULT NULL,
    address                   VARCHAR(255)           DEFAULT NULL,
    blood_group               VARCHAR(5)             DEFAULT NULL,
    medical_notes             LONGTEXT               DEFAULT NULL,   -- Encrypted clinical history (NFR-1)
    emergency_contact_name    VARCHAR(100)           DEFAULT NULL,
    emergency_contact_phone   VARCHAR(20)            DEFAULT NULL,
    emergency_contact_relationship VARCHAR(50)      DEFAULT NULL,
    created_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (patient_id),
    UNIQUE KEY uq_patients_id_number (id_number),
    CONSTRAINT fk_patients_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_patients_name   (last_name, first_name),
    INDEX idx_patients_id_num (id_number)
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- doctors 
-- FR-06 / Table 20: DoctorId, FullName, Specialization, LicenseNo (HPCSA), Email.
CREATE TABLE IF NOT EXISTS doctors (
    doctor_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    first_name     VARCHAR(50)  NOT NULL,
    last_name      VARCHAR(50)  NOT NULL,
    specialization VARCHAR(100)          DEFAULT NULL,
    license_number  VARCHAR(50)           DEFAULT NULL,   -- HPCSA license (DocLicenseNoHPCSA)
    practice_number VARCHAR(20)  NOT NULL,               -- HPCSA practice number
    phone           VARCHAR(20)           DEFAULT NULL,
    email           VARCHAR(255)          DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (doctor_id),
    UNIQUE KEY uq_doctors_license  (license_number),
    UNIQUE KEY uq_doctors_practice (practice_number),
    UNIQUE KEY uq_doctors_email   (email),
    CONSTRAINT fk_doctors_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- nurses 
-- FR-06 / Table 21: NurseId, FullName, Department, LicenseNo (SANC).
CREATE TABLE IF NOT EXISTS nurses (
    nurse_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    first_name     VARCHAR(50)  NOT NULL,
    last_name      VARCHAR(50)  NOT NULL,
    department     VARCHAR(100)          DEFAULT NULL,
    license_number  VARCHAR(50)           DEFAULT NULL,   -- SANC license number
    practice_number VARCHAR(20)  NOT NULL,               -- SANC practice number
    phone           VARCHAR(20)           DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (nurse_id),
    UNIQUE KEY uq_nurses_license  (license_number),
    UNIQUE KEY uq_nurses_practice (practice_number),
    CONSTRAINT fk_nurses_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- staff 
-- FR-06 / Table 22: StaffId, DoctorId (FK nullable), NurseId (FK nullable),
--                   Role (Doctor/Nurse/Admin), Status, CreatedBy (SuperAdmin).
-- Composite personnel table linking Doctor or Nurse to a unified staff record.
CREATE TABLE IF NOT EXISTS staff (
    staff_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    doctor_id  INT UNSIGNED          DEFAULT NULL,   -- FK to doctors — set for Doctor role
    nurse_id   INT UNSIGNED          DEFAULT NULL,   -- FK to nurses  — set for Nurse role
    first_name VARCHAR(50)  NOT NULL,
    last_name  VARCHAR(50)  NOT NULL,
    department VARCHAR(100)          DEFAULT NULL,
    position   VARCHAR(100)          DEFAULT NULL,
    role       VARCHAR(20)  NOT NULL DEFAULT 'Admin'
                            CHECK (role IN ('Doctor','Nurse','Admin')),
    status     VARCHAR(20)  NOT NULL DEFAULT 'Active'
                            CHECK (status IN ('Active','Inactive')),
    created_by INT UNSIGNED          DEFAULT NULL,   -- user_id of SuperAdmin who created
    phone      VARCHAR(20)           DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (staff_id),
    CONSTRAINT fk_staff_user
        FOREIGN KEY (user_id)   REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_staff_doctor
        FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_staff_nurse
        FOREIGN KEY (nurse_id)  REFERENCES nurses(nurse_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- appointments
-- FR-03 / Table 23: ApptId, PatientId, DoctorId, AppointmentDate, Status, Comments.
-- Business rule: double-booking prevented in application layer.
CREATE TABLE IF NOT EXISTS appointments (
    appointment_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id       INT UNSIGNED NOT NULL,
    doctor_id        INT UNSIGNED NOT NULL,
    appointment_date DATE         NOT NULL,
    appointment_time TIME         NOT NULL,
    status           ENUM('Requested','Confirmed','Amended','Declined','Completed','Cancelled')
                                  NOT NULL DEFAULT 'Requested',
    reason           TEXT                  DEFAULT NULL,   -- patient chief complaint; reused for doctor decline reason
    notes            TEXT                  DEFAULT NULL,   -- doctor post-visit notes
    proposed_date    DATE                  DEFAULT NULL,   -- doctor's amended date (awaiting patient approval)
    proposed_time    TIME                  DEFAULT NULL,   -- doctor's amended time (awaiting patient approval)
    reschedule_req   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (appointment_id),
    CONSTRAINT fk_appt_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_appt_doctor
        FOREIGN KEY (doctor_id)  REFERENCES doctors(doctor_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_appt_date    (appointment_date),
    INDEX idx_appt_doctor  (doctor_id, appointment_date, appointment_time),
    INDEX idx_appt_patient (patient_id, appointment_date)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- prescriptions
-- FR-02 / Table 24: Doctor issues prescription; Nurse attends; Pharmacist dispenses.
-- Business rule: Doctor, Nurse, Patient must all be verified first.
-- Audit log on every INSERT (trigger trg_prescription_audit).
-- Notes cannot be edited once saved (enforced at application layer).
CREATE TABLE IF NOT EXISTS prescriptions (
    rx_id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    patient_id   INT UNSIGNED  NOT NULL,
    doctor_id    INT UNSIGNED  NOT NULL,
    nurse_id     INT UNSIGNED           DEFAULT NULL,   -- attending nurse (nullable)
    medication   VARCHAR(255)  NOT NULL,
    dosage       VARCHAR(100)  NOT NULL,
    quantity     INT           NOT NULL CHECK (quantity > 0),
    instructions VARCHAR(500)           DEFAULT NULL,
    issued_date  DATE          NOT NULL DEFAULT (CURRENT_DATE),
    status       VARCHAR(20)   NOT NULL DEFAULT 'Issued'
                               CHECK (status IN ('Issued','Dispensed','Cancelled')),
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (rx_id),
    CONSTRAINT fk_rx_patient
        FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rx_doctor
        FOREIGN KEY (doctor_id)  REFERENCES doctors(doctor_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rx_nurse
        FOREIGN KEY (nurse_id)   REFERENCES nurses(nurse_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_rx_patient (patient_id),
    INDEX idx_rx_doctor  (doctor_id),
    INDEX idx_rx_date    (issued_date)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- billing_records
-- FR-04 / Table 25: BillId, PatientId, Amount (>0), PaymentStatus, AccountBalance,
--                   AccountTerm (1–12 months), BillingDate.
-- Business rule: record cannot be deleted until PaymentStatus = 'Paid'.
CREATE TABLE IF NOT EXISTS billing_records (
    bill_id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    patient_id      INT UNSIGNED    NOT NULL,
    appointment_id  INT UNSIGNED             DEFAULT NULL,   -- optional: links bill to appointment
    amount          DECIMAL(10,2)   NOT NULL CHECK (amount > 0),
    payment_status  VARCHAR(20)     NOT NULL DEFAULT 'Owing'
                                    CHECK (payment_status IN ('Paid','Owing','Account')),
    account_balance DECIMAL(10,2)   NOT NULL DEFAULT 0.00
                                    CHECK (account_balance >= 0),
    account_term    INT                      DEFAULT NULL    CHECK (account_term BETWEEN 1 AND 12),
    billing_date    DATE            NOT NULL DEFAULT (CURRENT_DATE),
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (bill_id),
    CONSTRAINT fk_bill_patient
        FOREIGN KEY (patient_id)     REFERENCES patients(patient_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,   -- cannot delete patient with unpaid bill
    CONSTRAINT fk_bill_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_bill_patient (patient_id),
    INDEX idx_bill_status  (payment_status)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- payments
-- FR-04 / ERD Payment entity: PaymentId, BillId, AmountPaid, PaymentMethod, PaymentDate.
-- Trigger trg_billing_auto_paid fires after INSERT to auto-update billing_records.
CREATE TABLE IF NOT EXISTS payments (
    payment_id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    bill_id         INT UNSIGNED    NOT NULL,
    amount_paid     DECIMAL(10,2)   NOT NULL CHECK (amount_paid > 0),
    payment_method  VARCHAR(30)     NOT NULL DEFAULT 'Cash'
                                    CHECK (payment_method IN ('Cash','Medical Aid','Card','Account')),
    payment_date    DATE            NOT NULL DEFAULT (CURRENT_DATE),
    reference_no    VARCHAR(100)             DEFAULT NULL,   -- bank/payment gateway ref
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (payment_id),
    CONSTRAINT fk_payment_bill
        FOREIGN KEY (bill_id) REFERENCES billing_records(bill_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_payment_bill (bill_id),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- staff_shifts
-- FR-05 / FR-06 / Table 30: ShiftId, StaffId, StartTime, EndTime, Role, Status.
-- Status values:
--   Scheduled : active shift (Doctor: 12hr; Nurse: 12hr per rotation rule)
--   Off Day   : full day off — start_time/end_time stored as NULL
--   Standby   : on-call shift (typically 8hr)
-- Nurse rotation rule (enforced app-layer): 4× Scheduled (12hr), 3× Off Day, 2× Standby (8hr)
-- Business rule: only SuperAdmin can assign/update/remove shifts (enforced app-layer).
CREATE TABLE IF NOT EXISTS staff_shifts (
    shift_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id    INT UNSIGNED NOT NULL,
    shift_date  DATE         NOT NULL,
    start_time  TIME                  DEFAULT NULL,   -- NULL for Off Day
    end_time    TIME                  DEFAULT NULL,   -- NULL for Off Day
    role        VARCHAR(20)  NOT NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'Scheduled'
                             CHECK (status IN ('Scheduled','Off Day','Standby')),
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (shift_id),
    CONSTRAINT fk_shift_staff
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_shift_times CHECK (
        status = 'Off Day'
        OR (start_time IS NOT NULL AND end_time IS NOT NULL AND end_time > start_time)
    ),
    INDEX idx_shift_staff (staff_id),
    INDEX idx_shift_date  (shift_date)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- inventory
-- FR-05 / Table 26: ItemId, ItemName, StockLevel (≥0), ReorderPoint (≥0), UnitPrice (>0).
-- Trigger trg_stock_reorder_alert fires when StockLevel drops to/below ReorderPoint.
CREATE TABLE IF NOT EXISTS inventory (
    item_id       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    item_name     VARCHAR(255)    NOT NULL,
    category      VARCHAR(100)             DEFAULT NULL,   -- e.g. Medication, Equipment, Consumable
    stock_level   INT             NOT NULL DEFAULT 0  CHECK (stock_level >= 0),
    reorder_point INT             NOT NULL DEFAULT 10 CHECK (reorder_point >= 0),
    unit_price    DECIMAL(10,2)   NOT NULL             CHECK (unit_price > 0),
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (item_id),
    INDEX idx_inventory_name (item_name)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- stock_alerts
-- FR-05 / ERD StockAlert entity: AlertId, ItemId (FK), StockLevel (at alert time), AlertDate.
-- Populated automatically by trigger trg_stock_reorder_alert.
CREATE TABLE IF NOT EXISTS stock_alerts (
    alert_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id      INT UNSIGNED NOT NULL,
    stock_level  INT          NOT NULL,   -- snapshot of StockLevel when alert fired
    alert_date   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_resolved  TINYINT(1)   NOT NULL DEFAULT 0,

    PRIMARY KEY (alert_id),
    CONSTRAINT fk_alert_item
        FOREIGN KEY (item_id) REFERENCES inventory(item_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_alert_item     (item_id),
    INDEX idx_alert_resolved (is_resolved)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- suppliers
-- FR-05 / Table 27: SupplierId, SupplierName, ContactEmail, Phone.
-- Business rule: a supplier can fulfill multiple POs.
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    supplier_name  VARCHAR(255)  NOT NULL,
    contact_email  VARCHAR(255)  NOT NULL,
    phone          VARCHAR(20)   NOT NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (supplier_id),
    UNIQUE KEY uq_supplier_email (contact_email)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- purchase_orders
-- FR-05 / Table 28: PurchaseOrderId, ItemId, SupplierId, Quantity (>0),
--                   TotalCost (>0), Status, OrderDate.
-- Business rule: one PO → one Supplier only.
CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    item_id     INT UNSIGNED    NOT NULL,
    supplier_id INT UNSIGNED    NOT NULL,
    quantity    INT             NOT NULL CHECK (quantity > 0),
    total_cost  DECIMAL(10,2)   NOT NULL CHECK (total_cost > 0),
    status      VARCHAR(20)     NOT NULL DEFAULT 'Pending'
                                CHECK (status IN ('Pending','Confirmed','Received')),
    order_date  DATE            NOT NULL DEFAULT (CURRENT_DATE),
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (po_id),
    CONSTRAINT fk_po_item
        FOREIGN KEY (item_id)     REFERENCES inventory(item_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_po_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_po_item     (item_id),
    INDEX idx_po_supplier (supplier_id),
    INDEX idx_po_status   (status)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- stock_receipts
-- FR-05 / Table 29: ReceiptId, PurchaseOrderId, QuantityReceived (>0), ReceivedDate.
-- Trigger trg_stock_receipt_update fires AFTER INSERT to increment inventory.stock_level.
CREATE TABLE IF NOT EXISTS stock_receipts (
    receipt_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_id             INT UNSIGNED NOT NULL,
    quantity_received INT          NOT NULL CHECK (quantity_received > 0),
    received_date     DATE         NOT NULL DEFAULT (CURRENT_DATE),
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (receipt_id),
    CONSTRAINT fk_receipt_po
        FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_receipt_po   (po_id),
    INDEX idx_receipt_date (received_date)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- audit_log
-- NFR-6 Auditability / Table 31: Immutable event log for every system action.
-- Rows are NEVER updated or deleted by the application.
CREATE TABLE IF NOT EXISTS audit_log (
    log_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED           DEFAULT NULL,   -- NULL for system/unauthenticated
    username    VARCHAR(50)            DEFAULT NULL,
    role        VARCHAR(20)            DEFAULT NULL,
    action      VARCHAR(50)   NOT NULL,                -- LOGIN|LOGOUT|INSERT|UPDATE|DELETE
    table_name  VARCHAR(50)            DEFAULT NULL,
    record_id   INT UNSIGNED           DEFAULT NULL,
    old_value   TEXT                   DEFAULT NULL,   -- JSON before
    new_value   TEXT                   DEFAULT NULL,   -- JSON after
    ip_address  VARCHAR(45)            DEFAULT NULL,   -- IPv6-capable
    user_agent  VARCHAR(255)           DEFAULT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (log_id),
    INDEX idx_audit_user    (user_id),
    INDEX idx_audit_action  (action),
    INDEX idx_audit_table   (table_name),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- sync_log 
-- NFR-3 Reliability / Table 32 / Table 6-2: Hybrid Cloud-Edge offline sync.
-- SyncStatus: 'Local' (queued on device) → 'Synced' (uploaded) | 'Failed'.
CREATE TABLE IF NOT EXISTS sync_log (
    log_id      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    table_name  VARCHAR(50)            DEFAULT NULL,
    record_id   INT UNSIGNED           DEFAULT NULL,
    action      VARCHAR(20)            DEFAULT NULL,   -- INSERT | UPDATE | DELETE
    payload     TEXT                   DEFAULT NULL,   -- JSON field snapshot (no passwords)
    sync_status VARCHAR(10)   NOT NULL DEFAULT 'Local',
    device_id   VARCHAR(100)           DEFAULT NULL,   -- client device fingerprint
    ip_address  VARCHAR(45)            DEFAULT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at   DATETIME              DEFAULT NULL,
    error_msg   VARCHAR(255)           DEFAULT NULL,

    PRIMARY KEY (log_id),
    INDEX idx_sync_status  (sync_status),
    INDEX idx_sync_table   (table_name),
    INDEX idx_sync_created (created_at)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ******************************************************************************
-- TRIGGERS  (§4.5.1 — five automated business rules)
-- ******************************************************************************

-- Trigger 1 — FR-05: Update inventory StockLevel when stock is received
DROP TRIGGER IF EXISTS trg_stock_receipt_update;
DELIMITER $$
CREATE TRIGGER trg_stock_receipt_update
AFTER INSERT ON stock_receipts
FOR EACH ROW
BEGIN
    -- Increment stock level by the quantity delivered
    UPDATE inventory
    SET    stock_level = stock_level + NEW.quantity_received,
           updated_at  = CURRENT_TIMESTAMP
    WHERE  item_id = (SELECT item_id FROM purchase_orders WHERE po_id = NEW.po_id);

    -- Close the PO
    UPDATE purchase_orders
    SET    status     = 'Received',
           updated_at = CURRENT_TIMESTAMP
    WHERE  po_id = NEW.po_id;
END$$
DELIMITER ;


-- Trigger 2 — FR-05: Fire stock_alert when StockLevel ≤ ReorderPoint
DROP TRIGGER IF EXISTS trg_stock_reorder_alert;
DELIMITER $$
CREATE TRIGGER trg_stock_reorder_alert
AFTER UPDATE ON inventory
FOR EACH ROW
BEGIN
    IF NEW.stock_level <= NEW.reorder_point AND OLD.stock_level > OLD.reorder_point THEN
        INSERT INTO stock_alerts (item_id, stock_level)
        VALUES (NEW.item_id, NEW.stock_level);
    END IF;
END$$
DELIMITER ;


-- Trigger 3 — FR-04: Auto-set PaymentStatus='Paid' when balance reaches 0
DROP TRIGGER IF EXISTS trg_billing_auto_paid;
DELIMITER $$
CREATE TRIGGER trg_billing_auto_paid
AFTER INSERT ON payments
FOR EACH ROW
BEGIN
    DECLARE v_balance DECIMAL(10,2);
    DECLARE v_current_balance DECIMAL(10,2);

    -- Get the current account balance and deduct payment
    SELECT account_balance INTO v_current_balance
    FROM   billing_records
    WHERE  bill_id = NEW.bill_id;

    SET v_balance = v_current_balance - NEW.amount_paid;
    IF v_balance < 0 THEN SET v_balance = 0; END IF;

    -- Update balance and conditionally mark as Paid
    UPDATE billing_records
    SET    account_balance = v_balance,
           payment_status  = IF(v_balance = 0, 'Paid', payment_status),
           updated_at      = CURRENT_TIMESTAMP
    WHERE  bill_id = NEW.bill_id;
END$$
DELIMITER ;


-- Trigger 4 — FR-02: Audit log entry on every prescription issued
DROP TRIGGER IF EXISTS trg_prescription_audit;
DELIMITER $$
CREATE TRIGGER trg_prescription_audit
AFTER INSERT ON prescriptions
FOR EACH ROW
BEGIN
    INSERT INTO audit_log
        (action, table_name, record_id, new_value, created_at)
    VALUES (
        'INSERT',
        'prescriptions',
        NEW.rx_id,
        JSON_OBJECT(
            'patient_id', NEW.patient_id,
            'doctor_id',  NEW.doctor_id,
            'medication', NEW.medication,
            'dosage',     NEW.dosage,
            'quantity',   NEW.quantity,
            'issued_date',NEW.issued_date
        ),
        CURRENT_TIMESTAMP
    );
END$$
DELIMITER ;


-- Trigger 5 — NFR-3: Ensure SyncLog entries default to 'Local' status
DROP TRIGGER IF EXISTS trg_sync_log_default_status;
DELIMITER $$
CREATE TRIGGER trg_sync_log_default_status
BEFORE INSERT ON sync_log
FOR EACH ROW
BEGIN
    IF NEW.sync_status IS NULL OR NEW.sync_status = '' THEN
        SET NEW.sync_status = 'Local';
    END IF;
END$$
DELIMITER ;


-- *******************************************************************************
-- MIGRATION NOTE: This script must be run on any existing database to add the Pharmacist role
-- ALTER TABLE users
--     MODIFY COLUMN role
--         ENUM('SuperAdmin','Admin','Doctor','Nurse','Patient','Pharmacist') NOT NULL;
-- ********************************************************************************
