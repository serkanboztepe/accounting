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
