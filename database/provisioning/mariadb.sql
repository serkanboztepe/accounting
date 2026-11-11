-- Hesap Asistanım: yeni firma veritabanı + firmaya özel kullanıcı (en az yetki).
-- Root yetkisiyle (DEFINER) çalışır; panelin hesap_admin kullanıcısı YALNIZ bunu çağırabilir,
-- hiçbir veritabanını okuyamaz. MariaDB desen (hesap\_%) üzerinden GRANT izni tanımadığı için.
-- Kurulum (root): mysql < database/provisioning/mariadb.sql
-- Önce: CREATE USER hesap_admin@localhost / @127.0.0.1 IDENTIFIED BY '...' (şifre panel .env
-- TENANCY_ADMIN_DB_PASSWORD). hesap_admin'e BAŞKA yetki verme.

CREATE DATABASE IF NOT EXISTS provisioning;
DROP PROCEDURE IF EXISTS provisioning.create_firm_database;
DELIMITER //
CREATE DEFINER=`root`@`localhost` PROCEDURE provisioning.create_firm_database(
    IN p_db VARCHAR(64), IN p_pass VARCHAR(128), IN p_existing TINYINT)
    SQL SECURITY DEFINER
BEGIN
    DECLARE v_exists INT;
    IF p_db IS NULL OR p_db NOT REGEXP '^hesap_[a-z0-9_]{1,40}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz veritabani adi (hesap_ ile baslamali, a-z0-9_)';
    END IF;
    IF p_pass IS NULL OR p_pass NOT REGEXP '^[A-Za-z0-9]{32,128}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz sifre bicimi';
    END IF;
    SELECT COUNT(*) INTO v_exists FROM information_schema.schemata WHERE schema_name = p_db;
    IF p_existing = 0 AND v_exists > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bu veritabani zaten var';
    END IF;
    IF p_existing = 1 AND v_exists = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Veritabani yok';
    END IF;
    IF p_existing = 0 THEN
        SET @s = CONCAT('CREATE DATABASE `', p_db, '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
    SET @s = CONCAT('DROP USER IF EXISTS `', p_db, '`@`localhost`, `', p_db, '`@`127.0.0.1`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('CREATE USER `', p_db, '`@`localhost` IDENTIFIED BY ''', p_pass, '''');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('CREATE USER `', p_db, '`@`127.0.0.1` IDENTIFIED BY ''', p_pass, '''');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('GRANT ALL PRIVILEGES ON `', p_db, '`.* TO `', p_db, '`@`localhost`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('GRANT ALL PRIVILEGES ON `', p_db, '`.* TO `', p_db, '`@`127.0.0.1`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END //
DELIMITER ;

GRANT EXECUTE ON PROCEDURE provisioning.create_firm_database TO `hesap_admin`@`localhost`;
GRANT EXECUTE ON PROCEDURE provisioning.create_firm_database TO `hesap_admin`@`127.0.0.1`;

-- ─────────────────────────────────────────────────────────────────────────────────────────────
-- Ajan asistanın serbest okuması (plan v5 §3): firma başına SALT-OKUR kullanıcı `<db>_ro`.
-- Yalnız aşağıdaki tablolarda SELECT (liste = App\Services\Agent\SqlGuard::TABLES; test eşitliği doğrular),
-- sorgu başına 3 sn, en çok 3 bağlantı. users / oturum / ayar / merkez tabloları YOK.
-- whatsapp_pending_expenses kolon bazında (media_url kapalı).
-- Kurulum (root): mysql < database/provisioning/mariadb.sql   (yordamlar yeniden oluşturulur, veriye dokunmaz)
-- Sonra panelde:  php artisan tenants:create-ro-user
-- ─────────────────────────────────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS provisioning.grant_firm_ro;
DROP PROCEDURE IF EXISTS provisioning.create_firm_ro_user;
DELIMITER //
CREATE DEFINER=`root`@`localhost` PROCEDURE provisioning.grant_firm_ro(IN p_db VARCHAR(64))
    SQL SECURITY DEFINER
BEGIN
    DECLARE v_table VARCHAR(64);
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_user VARCHAR(80);
    DECLARE cur CURSOR FOR
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = p_db AND table_type = 'BASE TABLE' AND table_name IN (
            'parties', 'party_ledger_entries', 'expenses', 'expense_categories', 'projects', 'agreements', 'agreement_items',
            'checks', 'products', 'units', 'stock_movements', 'sales', 'purchases', 'sale_returns', 'purchase_returns',
            'reminders', 'assistant_memories', 'whatsapp_messages',
            'contracts', 'contract_items', 'contract_deliveries', 'contract_payments', 'activity_logs');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF p_db IS NULL OR p_db NOT REGEXP '^(hesap_[a-z0-9_]{1,40}|yildiz_mgmt|muhasebe_mgmt|yenyapi_mgmt)$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz veritabani adi (hesap_ ile baslamali, a-z0-9_)';
    END IF;
    SET v_user = CONCAT(p_db, '_ro');
    IF (SELECT COUNT(*) FROM mysql.user WHERE user = v_user AND host = 'localhost') = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Salt-okur kullanici yok (once create_firm_ro_user)';
    END IF;

    OPEN cur;
    grant_loop: LOOP
        FETCH cur INTO v_table;
        IF v_done = 1 THEN
            LEAVE grant_loop;
        END IF;
        SET @s = CONCAT('GRANT SELECT ON `', p_db, '`.`', v_table, '` TO `', v_user, '`@`localhost`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        SET @s = CONCAT('GRANT SELECT ON `', p_db, '`.`', v_table, '` TO `', v_user, '`@`127.0.0.1`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END LOOP;
    CLOSE cur;

    IF (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = p_db AND table_name = 'whatsapp_pending_expenses') > 0 THEN
        SET @s = CONCAT('GRANT SELECT (id, phone, extracted, summary, status, follow_ups, followed_up_at, created_at, updated_at) ON `',
            p_db, '`.`whatsapp_pending_expenses` TO `', v_user, '`@`localhost`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        SET @s = CONCAT('GRANT SELECT (id, phone, extracted, summary, status, follow_ups, followed_up_at, created_at, updated_at) ON `',
            p_db, '`.`whatsapp_pending_expenses` TO `', v_user, '`@`127.0.0.1`');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //

CREATE DEFINER=`root`@`localhost` PROCEDURE provisioning.create_firm_ro_user(IN p_db VARCHAR(64), IN p_pass VARCHAR(128))
    SQL SECURITY DEFINER
BEGIN
    DECLARE v_user VARCHAR(80);
    IF p_db IS NULL OR p_db NOT REGEXP '^(hesap_[a-z0-9_]{1,40}|yildiz_mgmt|muhasebe_mgmt|yenyapi_mgmt)$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz veritabani adi (hesap_ ile baslamali, a-z0-9_)';
    END IF;
    IF p_pass IS NULL OR p_pass NOT REGEXP '^[A-Za-z0-9]{32,128}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz sifre bicimi';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = p_db) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Veritabani yok';
    END IF;
    SET v_user = CONCAT(p_db, '_ro');
    SET @s = CONCAT('DROP USER IF EXISTS `', v_user, '`@`localhost`, `', v_user, '`@`127.0.0.1`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('CREATE USER `', v_user, '`@`localhost` IDENTIFIED BY ''', p_pass, ''' WITH MAX_USER_CONNECTIONS 3 MAX_STATEMENT_TIME 3');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    SET @s = CONCAT('CREATE USER `', v_user, '`@`127.0.0.1` IDENTIFIED BY ''', p_pass, ''' WITH MAX_USER_CONNECTIONS 3 MAX_STATEMENT_TIME 3');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
END //
DELIMITER ;

GRANT EXECUTE ON PROCEDURE provisioning.create_firm_ro_user TO `hesap_admin`@`localhost`;
GRANT EXECUTE ON PROCEDURE provisioning.create_firm_ro_user TO `hesap_admin`@`127.0.0.1`;
GRANT EXECUTE ON PROCEDURE provisioning.grant_firm_ro TO `hesap_admin`@`localhost`;
GRANT EXECUTE ON PROCEDURE provisioning.grant_firm_ro TO `hesap_admin`@`127.0.0.1`;
