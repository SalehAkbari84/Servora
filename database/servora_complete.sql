-- Regenerated routines/triggers/events for migration 2026_05_27_000012_install_procedures_and_triggers
-- Leading DROP marker ensures the parser captures the trigger block that precedes the routine DROP statements.
DROP PROCEDURE IF EXISTS `__servora_baseline_marker__`;
-- MySQL dump 10.13  Distrib 8.0.46, for Win64 (x86_64)
--
-- Host: localhost    Database: servora
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_appointments_after_insert` AFTER INSERT ON `appointments` FOR EACH ROW BEGIN
    CALL WriteAuditLog(
        'appointments', NEW.id, 'ثبت',
        CONCAT('نوبت ثبت شد | کاربر: ', NEW.user_name,
               ' | کسب‌وکار: ', NEW.business_name,
               ' | خدمت: ', NEW.service_name,
               ' | تاریخ: ', NEW.date_shamsi,
               ' | ساعت: ', NEW.time_slot),
        NEW.user_id
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_appointments_before_update` BEFORE UPDATE ON `appointments` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        IF OLD.status = 'لغو شده' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'نوبت لغو شده قابل تغییر وضعیت نیست';
        ELSEIF OLD.status = 'انجام شده' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'نوبت انجام‌شده قابل تغییر وضعیت نیست';
        ELSEIF OLD.status = 'در انتظار'
               AND NEW.status NOT IN ('تایید شده', 'لغو شده') THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'انتقال وضعیت از «در انتظار» به این وضعیت مجاز نیست';
        ELSEIF OLD.status = 'تایید شده'
               AND NEW.status NOT IN ('انجام شده', 'لغو شده') THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'انتقال وضعیت از «تایید شده» به این وضعیت مجاز نیست';
        END IF;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_appointments_after_update` AFTER UPDATE ON `appointments` FOR EACH ROW BEGIN
    DECLARE v_queue_id       BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_queue_user_id  BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_queue_phone    VARCHAR(11)     DEFAULT '';
    DECLARE v_queue_svc_nm   VARCHAR(200)    DEFAULT '';
    DECLARE v_queue_biz_nm   VARCHAR(200)    DEFAULT '';
    DECLARE v_promo_dup      TINYINT         DEFAULT 0;

    
    
    
    DECLARE CONTINUE HANDLER FOR 1062
    BEGIN
        SET v_promo_dup = 1;
    END;

    
    
    
    IF OLD.status != 'لغو شده' AND NEW.status = 'لغو شده' THEN

        SELECT id, user_id, user_phone, service_name, business_name
        INTO   v_queue_id, v_queue_user_id, v_queue_phone,
               v_queue_svc_nm, v_queue_biz_nm
        FROM   queue
        WHERE  business_id = NEW.business_id
          AND  date_shamsi = NEW.date_shamsi
          AND  time_slot   = NEW.time_slot
          AND  status      = 'در انتظار'
        ORDER  BY created_at ASC
        LIMIT  1
        FOR UPDATE;

        IF v_queue_id > 0 THEN

            INSERT INTO appointments (
                user_id,     user_name,    user_phone,
                business_id, business_name,
                service_id,  service_name,
                duration_minutes, price,
                date_shamsi, time_slot,
                status
            )
            SELECT
                user_id,     user_name,    user_phone,
                business_id, business_name,
                service_id,  service_name,
                duration_minutes, price,
                date_shamsi, time_slot,
                'در انتظار'
            FROM   queue
            WHERE  id = v_queue_id;

            IF v_promo_dup = 1 THEN

                UPDATE queue
                SET    status = 'منقضی شده'
                WHERE  id     = v_queue_id;

                CALL WriteNotificationOutbox(
                    CONCAT('queue_expired_slot_', v_queue_id),
                    v_queue_user_id,
                    v_queue_phone,
                    'ارتقا_صف',
                    'اسلات زمانی منقضی شد',
                    CONCAT('متأسفانه اسلات «', v_queue_svc_nm, '» در «', v_queue_biz_nm,
                           '» تاریخ ', NEW.date_shamsi, ' ساعت ', NEW.time_slot,
                           ' توسط کاربر دیگری رزرو شد. لطفاً مجدداً ثبت‌نام کنید.'),
                    'queue',
                    v_queue_id
                );

                CALL WriteAuditLog(
                    'queue', v_queue_id, 'منقضی',
                    CONCAT('ارتقا شکست خورد — اسلات توسط کاربر دیگری رزرو شد',
                           ' | اسلات: ', NEW.date_shamsi, ' ', NEW.time_slot,
                           ' | کسب‌وکار: ', NEW.business_id),
                    NULL
                );

            ELSE

                UPDATE queue
                SET    status = 'پذیرفته شده'
                WHERE  id     = v_queue_id;

                CALL WriteNotificationOutbox(
                    CONCAT('queue_promoted_', v_queue_id),
                    v_queue_user_id,
                    v_queue_phone,
                    'ارتقا_صف',
                    'نوبت برای شما آزاد شد!',
                    CONCAT('نوبت «', v_queue_svc_nm, '» در «', v_queue_biz_nm,
                           '» تاریخ ', NEW.date_shamsi, ' ساعت ', NEW.time_slot,
                           ' برای شما رزرو شد.'),
                    'queue',
                    v_queue_id
                );

                CALL WriteAuditLog(
                    'queue', v_queue_id, 'ارتقا_صف',
                    CONCAT('کاربر ', v_queue_user_id, ' از صف به نوبت ارتقا یافت',
                           ' | اسلات: ', NEW.date_shamsi, ' ', NEW.time_slot,
                           ' | لغوکننده قبلی: ', COALESCE(CAST(NEW.cancelled_by AS CHAR), 'سیستم')),
                    NULL
                );

            END IF;

        END IF;

        CALL WriteAuditLog(
            'appointments', NEW.id, 'لغو',
            CONCAT('نوبت لغو شد | کاربر: ', NEW.user_name,
                   ' | خدمت: ', NEW.service_name,
                   ' | تاریخ: ', NEW.date_shamsi,
                   ' | دلیل: ', COALESCE(NEW.cancel_reason, 'ذکر نشده')),
            NEW.cancelled_by
        );

    END IF;

    
    
    
    IF OLD.status != 'انجام شده' AND NEW.status = 'انجام شده' THEN
        CALL WriteAuditLog(
            'appointments', NEW.id, 'ویرایش',
            CONCAT('نوبت انجام شد | کاربر: ', NEW.user_name,
                   ' | خدمت: ', NEW.service_name,
                   ' | تاریخ: ', NEW.date_shamsi),
            NULL
        );
    END IF;

    
    
    
    IF OLD.status = 'در انتظار' AND NEW.status = 'تایید شده' THEN
        CALL WriteNotificationOutbox(
            CONCAT('appt_confirmed_', NEW.id),
            NEW.user_id,
            NEW.user_phone,
            'رزرو_موفق',
            'نوبت شما تایید شد',
            CONCAT('نوبت «', NEW.service_name, '» در «', NEW.business_name,
                   '» تاریخ ', NEW.date_shamsi, ' ساعت ', NEW.time_slot, ' تایید شد.'),
            'appointments',
            NEW.id
        );
    END IF;

END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_bv_after_insert` AFTER INSERT ON `business_verification` FOR EACH ROW BEGIN
    CALL WriteAuditLog(
        'business_verification', NEW.id, 'ثبت',
        CONCAT('درخواست تایید برای «', NEW.business_name, '» ثبت شد'),
        NULL
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_bv_after_update` AFTER UPDATE ON `business_verification` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN

        IF NEW.status = 'تایید شده' THEN
            UPDATE businesses
            SET    is_verified = 1
            WHERE  id          = NEW.business_id;

        ELSEIF NEW.status = 'رد شده' THEN
            UPDATE businesses
            SET    is_verified = 0
            WHERE  id          = NEW.business_id;
        END IF;

        CALL WriteAuditLog(
            'business_verification', NEW.id,
            IF(NEW.status = 'تایید شده', 'تایید', 'رد'),
            CONCAT('وضعیت «', NEW.business_name, '» به «', NEW.status, '» تغییر یافت',
                   COALESCE(CONCAT(' | یادداشت: ', NEW.admin_note), '')),
            NEW.reviewed_by
        );

    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_businesses_after_insert` AFTER INSERT ON `businesses` FOR EACH ROW BEGIN
    CALL WriteAuditLog(
        'businesses', NEW.id, 'ثبت',
        CONCAT('کسب‌وکار جدید ثبت شد | نام: «', NEW.name, '»',
               ' | دسته: ', NEW.category_name,
               ' | مالک: ', NEW.owner_name),
        NEW.owner_user_id
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reviews_after_insert` AFTER INSERT ON `reviews` FOR EACH ROW BEGIN
    IF NEW.is_visible = 1 THEN
        UPDATE businesses
        SET    rating_sum    = rating_sum + NEW.rating,
               rating_count  = rating_count + 1,
               total_reviews = total_reviews + 1,
               rating_avg    = ROUND((rating_sum + NEW.rating) / (rating_count + 1), 2)
        WHERE  id = NEW.business_id;
    ELSE
        UPDATE businesses
        SET    total_reviews = total_reviews + 1
        WHERE  id = NEW.business_id;
    END IF;

    CALL WriteAuditLog(
        'reviews', NEW.id, 'ثبت',
        CONCAT('نظر جدید | امتیاز: ', NEW.rating,
               ' | کسب‌وکار: ', NEW.business_name,
               ' | کاربر: ', NEW.user_name),
        NEW.user_id
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reviews_after_update` AFTER UPDATE ON `reviews` FOR EACH ROW BEGIN
    DECLARE v_audit_desc TEXT DEFAULT '';

    IF OLD.rating != NEW.rating OR OLD.is_visible != NEW.is_visible THEN
        UPDATE businesses
        SET    rating_sum   = rating_sum
                              - IF(OLD.is_visible = 1, OLD.rating, 0)
                              + IF(NEW.is_visible = 1, NEW.rating, 0),
               rating_count = rating_count
                              - IF(OLD.is_visible = 1, 1, 0)
                              + IF(NEW.is_visible = 1, 1, 0),
               rating_avg   = ROUND(
                                  (rating_sum
                                   - IF(OLD.is_visible = 1, OLD.rating, 0)
                                   + IF(NEW.is_visible = 1, NEW.rating, 0))
                                  / NULLIF(
                                      rating_count
                                      - IF(OLD.is_visible = 1, 1, 0)
                                      + IF(NEW.is_visible = 1, 1, 0),
                                  0),
                                  2)
        WHERE  id = NEW.business_id;
    END IF;

    IF OLD.rating != NEW.rating THEN
        SET v_audit_desc = CONCAT(v_audit_desc, ' | امتیاز: ', OLD.rating, ' → ', NEW.rating);
    END IF;

    IF OLD.is_visible != NEW.is_visible THEN
        SET v_audit_desc = CONCAT(v_audit_desc, ' | نمایش: ',
                                  IF(OLD.is_visible, 'فعال', 'غیرفعال'),
                                  ' → ',
                                  IF(NEW.is_visible, 'فعال', 'غیرفعال'));
    END IF;

    IF v_audit_desc != '' THEN
        CALL WriteAuditLog(
            'reviews', NEW.id, 'ویرایش',
            CONCAT('نظر ویرایش شد | کسب‌وکار: ', NEW.business_name,
                   ' | کاربر: ', NEW.user_name, v_audit_desc),
            NULL
        );
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_reviews_after_delete` AFTER DELETE ON `reviews` FOR EACH ROW BEGIN
    IF OLD.is_visible = 1 THEN
        UPDATE businesses
        SET    rating_sum    = GREATEST(rating_sum - OLD.rating, 0),
               rating_count  = GREATEST(rating_count - 1, 0),
               total_reviews = GREATEST(total_reviews - 1, 0),
               rating_avg    = COALESCE(
                                   ROUND(
                                       GREATEST(rating_sum - OLD.rating, 0)
                                       / NULLIF(GREATEST(rating_count - 1, 0), 0),
                                       2),
                                   0.00)
        WHERE  id = OLD.business_id;
    ELSE
        UPDATE businesses
        SET    total_reviews = GREATEST(total_reviews - 1, 0)
        WHERE  id = OLD.business_id;
    END IF;

    CALL WriteAuditLog(
        'reviews', OLD.id, 'حذف',
        CONCAT('نظر حذف شد | امتیاز: ', OLD.rating,
               ' | کسب‌وکار: ', OLD.business_name,
               ' | کاربر: ', OLD.user_name),
        NULL
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_users_after_insert` AFTER INSERT ON `users` FOR EACH ROW BEGIN
    CALL WriteAuditLog(
        'users', NEW.id, 'ثبت',
        CONCAT('کاربر جدید ثبت‌نام کرد | نام: ', NEW.full_name,
               ' | موبایل: ', NEW.phone,
               ' | نقش: ', NEW.role),
        NULL
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_users_after_update` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    DECLARE v_desc TEXT DEFAULT '';

    IF OLD.role != NEW.role THEN
        SET v_desc = CONCAT(v_desc, ' | نقش: ', OLD.role, ' → ', NEW.role);
    END IF;

    IF OLD.is_active != NEW.is_active THEN
        SET v_desc = CONCAT(v_desc, ' | وضعیت: ', IF(NEW.is_active, 'فعال', 'غیرفعال'));
    END IF;

    IF v_desc != '' THEN
        CALL WriteAuditLog(
            'users', NEW.id, 'ویرایش',
            CONCAT('اطلاعات کاربر «', NEW.full_name, '» تغییر کرد', v_desc),
            NULL
        );
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;

--
-- Dumping events for database 'servora'
--
/*!50106 SET @save_time_zone= @@TIME_ZONE */ ;
/*!50106 DROP EVENT IF EXISTS `evt_cleanup_outbox` */;
DELIMITER ;;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;;
/*!50003 SET character_set_client  = utf8mb4 */ ;;
/*!50003 SET character_set_results = utf8mb4 */ ;;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;;
/*!50003 SET @saved_time_zone      = @@time_zone */ ;;
/*!50003 SET time_zone             = '+03:30' */ ;;
/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`localhost`*/ /*!50106 EVENT `evt_cleanup_outbox` ON SCHEDULE EVERY 1 DAY STARTS '2026-05-27 00:00:00' ON COMPLETION PRESERVE ENABLE COMMENT 'پاکسازی ردیف‌های قدیمی notification_outbox' DO DELETE FROM notification_outbox
    WHERE  (status = 'delivered' AND processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
       OR  (status = 'failed'    AND processed_at < DATE_SUB(NOW(), INTERVAL 7  DAY)) */ ;;
/*!50003 SET time_zone             = @saved_time_zone */ ;;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;;
/*!50003 SET character_set_client  = @saved_cs_client */ ;;
/*!50003 SET character_set_results = @saved_cs_results */ ;;
/*!50003 SET collation_connection  = @saved_col_connection */ ;;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;;
/*!50106 DROP EVENT IF EXISTS `evt_expire_stale_queue` */;;
DELIMITER ;;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;;
/*!50003 SET character_set_client  = utf8mb4 */ ;;
/*!50003 SET character_set_results = utf8mb4 */ ;;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;;
/*!50003 SET @saved_time_zone      = @@time_zone */ ;;
/*!50003 SET time_zone             = '+03:30' */ ;;
/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`localhost`*/ /*!50106 EVENT `evt_expire_stale_queue` ON SCHEDULE EVERY 1 HOUR STARTS '2026-05-26 14:31:31' ON COMPLETION PRESERVE ENABLE COMMENT 'انقضای خودکار ردیف‌های صف انتظار قدیمی' DO UPDATE queue
    SET    status     = 'منقضی شده',
           updated_at = NOW()
    WHERE  status     IN ('در انتظار', 'اطلاع داده شده')
      AND  created_at < NOW() - INTERVAL 2 DAY
    LIMIT  500 */ ;;
/*!50003 SET time_zone             = @saved_time_zone */ ;;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;;
/*!50003 SET character_set_client  = @saved_cs_client */ ;;
/*!50003 SET character_set_results = @saved_cs_results */ ;;
/*!50003 SET collation_connection  = @saved_col_connection */ ;;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;;
/*!50106 DROP EVENT IF EXISTS `evt_reset_stuck_processing` */;;
DELIMITER ;;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;;
/*!50003 SET character_set_client  = utf8mb4 */ ;;
/*!50003 SET character_set_results = utf8mb4 */ ;;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;;
/*!50003 SET @saved_time_zone      = @@time_zone */ ;;
/*!50003 SET time_zone             = '+03:30' */ ;;
/*!50106 CREATE*/ /*!50117 DEFINER=`root`@`localhost`*/ /*!50106 EVENT `evt_reset_stuck_processing` ON SCHEDULE EVERY 5 MINUTE STARTS '2026-05-26 14:31:31' ON COMPLETION PRESERVE ENABLE COMMENT 'بازیابی ردیف‌های notification_outbox گیر کرده در processing' DO UPDATE notification_outbox
    SET    status        = 'pending',
           next_retry_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
    WHERE  status    = 'processing'
      AND  updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) */ ;;
/*!50003 SET time_zone             = @saved_time_zone */ ;;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;;
/*!50003 SET character_set_client  = @saved_cs_client */ ;;
/*!50003 SET character_set_results = @saved_cs_results */ ;;
/*!50003 SET collation_connection  = @saved_col_connection */ ;;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;;
DELIMITER ;
/*!50106 SET TIME_ZONE= @save_time_zone */ ;

--
-- Dumping routines for database 'servora'
--
/*!50003 DROP FUNCTION IF EXISTS `CalcBusinessRating` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `CalcBusinessRating`(
    p_business_id BIGINT UNSIGNED
) RETURNS decimal(3,2)
    READS SQL DATA
BEGIN
    DECLARE v_avg DECIMAL(3,2) DEFAULT 0.00;

    SELECT COALESCE(ROUND(rating_sum / NULLIF(rating_count, 0), 2), 0.00)
    INTO   v_avg
    FROM   businesses
    WHERE  id = p_business_id;

    RETURN v_avg;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP FUNCTION IF EXISTS `GetNextQueuePosition` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `GetNextQueuePosition`(
    p_business_id   BIGINT UNSIGNED,
    p_date_shamsi   CHAR(10),
    p_time_slot     CHAR(5)
) RETURNS int unsigned
    READS SQL DATA
BEGIN
    DECLARE v_count INT UNSIGNED DEFAULT 0;

    SELECT COUNT(*)
    INTO   v_count
    FROM   queue
    WHERE  business_id = p_business_id
      AND  date_shamsi = p_date_shamsi
      AND  time_slot   = p_time_slot
      AND  status      IN ('در انتظار', 'اطلاع داده شده');

    RETURN v_count + 1;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP FUNCTION IF EXISTS `IsSlotAvailable` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `IsSlotAvailable`(
    p_business_id   BIGINT UNSIGNED,
    p_date_shamsi   CHAR(10),
    p_time_slot     CHAR(5)
) RETURNS tinyint(1)
    READS SQL DATA
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*)
    INTO   v_count
    FROM   appointments
    WHERE  business_id = p_business_id
      AND  date_shamsi = p_date_shamsi
      AND  time_slot   = p_time_slot
      AND  status      IN ('در انتظار', 'تایید شده', 'انجام شده');

    RETURN IF(v_count = 0, 1, 0);
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `AddToQueue` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `AddToQueue`(
    IN  p_user_id      BIGINT UNSIGNED,
    IN  p_business_id  BIGINT UNSIGNED,
    IN  p_service_id   BIGINT UNSIGNED,
    IN  p_date_shamsi  CHAR(10),
    IN  p_time_slot    CHAR(5),
    IN  p_day_of_week  TINYINT UNSIGNED,
    OUT p_result_code  INT,
    OUT p_result_msg   VARCHAR(500),
    OUT p_queue_id     BIGINT UNSIGNED,
    OUT p_position     INT UNSIGNED
)
AddToQueue: BEGIN
    DECLARE v_user_name    VARCHAR(120)      DEFAULT NULL;
    DECLARE v_user_phone   VARCHAR(11)       DEFAULT NULL;
    DECLARE v_biz_name     VARCHAR(200)      DEFAULT NULL;
    DECLARE v_biz_active   TINYINT(1)        DEFAULT NULL;
    DECLARE v_biz_verified TINYINT(1)        DEFAULT NULL;
    DECLARE v_svc_name     VARCHAR(200)      DEFAULT NULL;
    DECLARE v_svc_duration SMALLINT UNSIGNED DEFAULT 30;
    DECLARE v_svc_price    DECIMAL(12,0)     DEFAULT 0;
    DECLARE v_svc_active   TINYINT(1)        DEFAULT NULL;
    DECLARE v_already_in   INT               DEFAULT 0;
    DECLARE v_fifo_pos     INT UNSIGNED      DEFAULT 1;
    DECLARE v_dup_queue    TINYINT           DEFAULT 0;
    DECLARE v_slot_active  TINYINT(1)        DEFAULT NULL;

    DECLARE CONTINUE HANDLER FOR 1062
    BEGIN
        SET v_dup_queue = 1;
    END;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_result_code = 99;
        SET p_result_msg  = 'خطای داخلی سرور';
        SET p_queue_id    = 0;
        SET p_position    = 0;
    END;

    SELECT full_name, phone
    INTO   v_user_name, v_user_phone
    FROM   users
    WHERE  id        = p_user_id
      AND  is_active = 1
    LIMIT  1;

    IF v_user_name IS NULL THEN
        SET p_result_code = 1;
        SET p_result_msg  = 'کاربر یافت نشد یا غیرفعال است';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    SELECT name, is_active, is_verified
    INTO   v_biz_name, v_biz_active, v_biz_verified
    FROM   businesses
    WHERE  id = p_business_id
    LIMIT  1;

    IF v_biz_name IS NULL THEN
        SET p_result_code = 2;
        SET p_result_msg  = 'کسب‌وکار یافت نشد';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    IF v_biz_active = 0 THEN
        SET p_result_code = 3;
        SET p_result_msg  = 'کسب‌وکار غیرفعال است';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    IF v_biz_verified = 0 THEN
        SET p_result_code = 4;
        SET p_result_msg  = 'کسب‌وکار هنوز تایید نشده است';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    SELECT service_name, duration_minutes, price, is_active
    INTO   v_svc_name, v_svc_duration, v_svc_price, v_svc_active
    FROM   services
    WHERE  id          = p_service_id
      AND  business_id = p_business_id
    LIMIT  1;

    IF v_svc_name IS NULL OR v_svc_active = 0 THEN
        SET p_result_code = 5;
        SET p_result_msg  = 'خدمت مورد نظر یافت نشد یا فعال نیست';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    
    
    SELECT is_active
    INTO   v_slot_active
    FROM   business_slots
    WHERE  business_id = p_business_id
      AND  day_of_week = p_day_of_week
      AND  time_slot   = p_time_slot
    LIMIT  1;

    IF v_slot_active IS NULL THEN
        SET p_result_code = 8;
        SET p_result_msg  = 'این اسلات زمانی در برنامه کاری کسب‌وکار تعریف نشده است';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    IF v_slot_active = 0 THEN
        SET p_result_code = 8;
        SET p_result_msg  = 'این اسلات زمانی موقتاً غیرفعال است';
        SET p_queue_id    = 0;
        SET p_position    = 0;
        LEAVE AddToQueue;
    END IF;

    START TRANSACTION;

        
        
        DELETE FROM queue
        WHERE  user_id     = p_user_id
          AND  business_id = p_business_id
          AND  date_shamsi = p_date_shamsi
          AND  time_slot   = p_time_slot
          AND  status      = 'منقضی شده';

        
        
        SELECT COUNT(*)
        INTO   v_already_in
        FROM   queue
        WHERE  user_id     = p_user_id
          AND  business_id = p_business_id
          AND  date_shamsi = p_date_shamsi
          AND  time_slot   = p_time_slot
          AND  status      IN ('در انتظار', 'اطلاع داده شده')
        FOR UPDATE;

        IF v_already_in > 0 THEN
            ROLLBACK;
            SET p_result_code = 6;
            SET p_result_msg  = 'شما از قبل در صف انتظار این اسلات هستید';
            SET p_queue_id    = 0;
            SET p_position    = 0;
            LEAVE AddToQueue;
        END IF;

        
        SELECT COUNT(*)
        INTO   v_already_in
        FROM   queue
        WHERE  user_id     = p_user_id
          AND  business_id = p_business_id
          AND  date_shamsi = p_date_shamsi
          AND  time_slot   = p_time_slot
          AND  status      = 'پذیرفته شده';

        IF v_already_in > 0 THEN
            ROLLBACK;
            SET p_result_code = 7;
            SET p_result_msg  = 'شما قبلاً از این صف ارتقا یافته و نوبت گرفته‌اید';
            SET p_queue_id    = 0;
            SET p_position    = 0;
            LEAVE AddToQueue;
        END IF;

        SELECT COUNT(*) + 1
        INTO   v_fifo_pos
        FROM   queue
        WHERE  business_id = p_business_id
          AND  date_shamsi = p_date_shamsi
          AND  time_slot   = p_time_slot
          AND  status      IN ('در انتظار', 'اطلاع داده شده');

        INSERT INTO queue (
            business_id,   business_name,
            user_id,       user_name,      user_phone,
            service_id,    service_name,
            duration_minutes, price,
            date_shamsi,   time_slot,
            status
        ) VALUES (
            p_business_id, v_biz_name,
            p_user_id,     v_user_name,    v_user_phone,
            p_service_id,  v_svc_name,
            v_svc_duration, v_svc_price,
            p_date_shamsi,  p_time_slot,
            'در انتظار'
        );

        IF v_dup_queue = 1 THEN
            ROLLBACK;
            SET p_result_code = 6;
            SET p_result_msg  = 'شما از قبل در صف انتظار این اسلات هستید';
            SET p_queue_id    = 0;
            SET p_position    = 0;
            LEAVE AddToQueue;
        END IF;

        SET p_queue_id = LAST_INSERT_ID();

        CALL WriteNotificationOutbox(
            CONCAT('queue_added_', p_queue_id),
            p_user_id,
            v_user_phone,
            'ثبت_صف',
            'ثبت در صف انتظار',
            CONCAT('شما در موقعیت ', v_fifo_pos, ' صف انتظار «',
                   v_svc_name, '» در «', v_biz_name,
                   '» تاریخ ', p_date_shamsi, ' قرار گرفتید.'),
            'queue',
            p_queue_id
        );

    COMMIT;

    SET p_result_code = 0;
    SET p_result_msg  = CONCAT('با موفقیت در موقعیت ', v_fifo_pos, ' صف قرار گرفتید');
    SET p_position    = v_fifo_pos;

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `CancelAppointment` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `CancelAppointment`(
    IN  p_appointment_id  BIGINT UNSIGNED,
    IN  p_cancelled_by    BIGINT UNSIGNED,
    IN  p_cancel_reason   VARCHAR(500),
    OUT p_result_code     INT,
    OUT p_result_msg      VARCHAR(500)
)
CancelAppointment: BEGIN
    DECLARE v_status      VARCHAR(20)      DEFAULT NULL;
    DECLARE v_user_id     BIGINT UNSIGNED  DEFAULT NULL;
    DECLARE v_user_phone  VARCHAR(11)      DEFAULT NULL;
    DECLARE v_biz_name    VARCHAR(200)     DEFAULT NULL;
    DECLARE v_svc_name    VARCHAR(200)     DEFAULT NULL;
    DECLARE v_date_shamsi CHAR(10)         DEFAULT NULL;
    DECLARE v_done        TINYINT          DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_result_code = 99;
        SET p_result_msg  = 'خطای داخلی سرور';
    END;

    START TRANSACTION;

        SELECT status, user_id, user_phone, business_name, service_name, date_shamsi
        INTO   v_status, v_user_id, v_user_phone, v_biz_name, v_svc_name, v_date_shamsi
        FROM   appointments
        WHERE  id = p_appointment_id
        FOR UPDATE;

        IF v_status IS NULL THEN
            SET v_done        = 1;
            SET p_result_code = 1;
            SET p_result_msg  = 'نوبت یافت نشد';
        ELSEIF v_status IN ('لغو شده', 'انجام شده') THEN
            SET v_done        = 1;
            SET p_result_code = 2;
            SET p_result_msg  = CONCAT('نوبت در وضعیت «', v_status, '» قابل لغو نیست');
        END IF;

        IF v_done = 1 THEN
            ROLLBACK;
            LEAVE CancelAppointment;
        END IF;

        UPDATE appointments
        SET    status        = 'لغو شده',
               cancel_reason = p_cancel_reason,
               cancelled_by  = p_cancelled_by,
               cancelled_at  = NOW()
        WHERE  id            = p_appointment_id;

        CALL WriteNotificationOutbox(
            CONCAT('appt_cancelled_', p_appointment_id),
            v_user_id,
            v_user_phone,
            'لغو_نوبت',
            'نوبت شما لغو شد',
            CONCAT('نوبت «', v_svc_name, '» در «', v_biz_name,
                   '» تاریخ ', v_date_shamsi, ' لغو گردید. دلیل: ',
                   COALESCE(p_cancel_reason, 'ذکر نشده')),
            'appointments',
            p_appointment_id
        );

    COMMIT;

    SET p_result_code = 0;
    SET p_result_msg  = 'نوبت با موفقیت لغو شد';

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `ClaimPendingNotifications` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `ClaimPendingNotifications`(
    IN p_batch_size TINYINT UNSIGNED
)
ClaimPendingNotifications: BEGIN
    DECLARE v_batch_size TINYINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    SET v_batch_size = LEAST(p_batch_size, 100);

    DROP TEMPORARY TABLE IF EXISTS tmp_outbox_ids;
    CREATE TEMPORARY TABLE tmp_outbox_ids (
        id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE = MEMORY;

    START TRANSACTION;

        INSERT INTO tmp_outbox_ids (id)
        SELECT   id
        FROM     notification_outbox
        WHERE    status = 'pending'
          AND    (next_retry_at IS NULL OR next_retry_at <= NOW())
        ORDER BY id ASC
        LIMIT    v_batch_size
        FOR UPDATE SKIP LOCKED;

        UPDATE notification_outbox o
          JOIN tmp_outbox_ids t ON t.id = o.id
        SET    o.status        = 'processing',
               o.attempt_count = o.attempt_count + 1;

    COMMIT;

    SELECT o.id,
           o.user_id,
           o.user_phone,
           o.type,
           o.title,
           o.body,
           o.related_entity_type,
           o.related_entity_id,
           o.idempotency_key,
           o.attempt_count
    FROM   notification_outbox o
      JOIN tmp_outbox_ids t ON t.id = o.id;

    DROP TEMPORARY TABLE IF EXISTS tmp_outbox_ids;

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `CreateAppointment` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_persian_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `CreateAppointment`(
    IN  p_user_id        BIGINT UNSIGNED,
    IN  p_business_id    BIGINT UNSIGNED,
    IN  p_service_id     BIGINT UNSIGNED,
    IN  p_date_shamsi    CHAR(10),
    IN  p_time_slot      CHAR(5),
    IN  p_day_of_week    TINYINT UNSIGNED,
    OUT p_result_code    TINYINT,
    OUT p_result_msg     VARCHAR(300),
    OUT p_appointment_id BIGINT UNSIGNED
)
CreateAppointment: BEGIN
    DECLARE v_user_name    VARCHAR(120)      DEFAULT NULL;
    DECLARE v_user_phone   VARCHAR(11)       DEFAULT NULL;
    DECLARE v_biz_name     VARCHAR(200)      DEFAULT NULL;
    DECLARE v_biz_verified TINYINT(1)        DEFAULT NULL;
    DECLARE v_biz_active   TINYINT(1)        DEFAULT NULL;
    DECLARE v_svc_name     VARCHAR(200)      DEFAULT NULL;
    DECLARE v_svc_duration SMALLINT UNSIGNED DEFAULT 30;
    DECLARE v_svc_price    DECIMAL(12,0)     DEFAULT 0;
    DECLARE v_svc_active   TINYINT(1)        DEFAULT NULL;
    DECLARE v_new_id       BIGINT UNSIGNED   DEFAULT 0;
    DECLARE v_dup_slot     TINYINT           DEFAULT 0;
    DECLARE v_slot_active  TINYINT(1)        DEFAULT NULL;

    DECLARE CONTINUE HANDLER FOR 1062 BEGIN SET v_dup_slot = 1; END;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_result_code    = 99;
        SET p_result_msg     = "خطای داخلی سرور - لطفاً دوباره تلاش کنید";
        SET p_appointment_id = 0;
    END;

    SET p_result_code = 0; SET p_result_msg = ""; SET p_appointment_id = 0;

    SELECT full_name, phone INTO v_user_name, v_user_phone
    FROM users WHERE id=p_user_id AND is_active=1 LIMIT 1;
    IF v_user_name IS NULL THEN
        SET p_result_code=1; SET p_result_msg="کاربر یافت نشد یا غیرفعال است";
        LEAVE CreateAppointment;
    END IF;

    SELECT name, is_verified, is_active INTO v_biz_name, v_biz_verified, v_biz_active
    FROM businesses WHERE id=p_business_id LIMIT 1;
    IF v_biz_name IS NULL THEN
        SET p_result_code=2; SET p_result_msg="کسب‌وکار یافت نشد";
        LEAVE CreateAppointment;
    END IF;
    IF v_biz_active=0 THEN
        SET p_result_code=3; SET p_result_msg="کسب‌وکار غیرفعال است";
        LEAVE CreateAppointment;
    END IF;
    IF v_biz_verified=0 THEN
        SET p_result_code=4; SET p_result_msg="کسب‌وکار هنوز تایید نشده است";
        LEAVE CreateAppointment;
    END IF;

    SELECT service_name, duration_minutes, price, is_active
    INTO v_svc_name, v_svc_duration, v_svc_price, v_svc_active
    FROM services WHERE id=p_service_id AND business_id=p_business_id LIMIT 1;
    IF v_svc_name IS NULL OR v_svc_active=0 THEN
        SET p_result_code=5; SET p_result_msg="خدمت مورد نظر یافت نشد یا فعال نیست";
        LEAVE CreateAppointment;
    END IF;

    SELECT is_active INTO v_slot_active
    FROM business_slots
    WHERE business_id=p_business_id AND day_of_week=p_day_of_week AND time_slot=p_time_slot LIMIT 1;
    IF v_slot_active IS NULL THEN
        SET p_result_code=7; SET p_result_msg="این اسلات زمانی در برنامه کاری کسب‌وکار تعریف نشده است";
        LEAVE CreateAppointment;
    END IF;
    IF v_slot_active=0 THEN
        SET p_result_code=7; SET p_result_msg="این اسلات زمانی موقتاً غیرفعال است";
        LEAVE CreateAppointment;
    END IF;

    START TRANSACTION;

        INSERT INTO appointments (
            user_id, user_name, user_phone,
            business_id, business_name,
            service_id, service_name,
            duration_minutes, price,
            date_shamsi, time_slot, status
        ) VALUES (
            p_user_id, v_user_name, v_user_phone,
            p_business_id, v_biz_name,
            p_service_id, v_svc_name,
            v_svc_duration, v_svc_price,
            p_date_shamsi, p_time_slot, "در انتظار"
        );

        IF v_dup_slot=1 THEN
            ROLLBACK;
            SET p_result_code=6; SET p_result_msg="این اسلات زمانی قبلاً رزرو شده است";
            SET p_appointment_id=0;
            LEAVE CreateAppointment;
        END IF;

        SET v_new_id = LAST_INSERT_ID();

        CALL WriteNotificationOutbox(
            CONCAT("appt_created_", v_new_id),
            p_user_id, v_user_phone,
            "رزرو_موفق", "نوبت شما ثبت شد",
            CONCAT("نوبت شما برای «", v_svc_name, "» در «", v_biz_name,
                   "» تاریخ ", p_date_shamsi, " ساعت ", p_time_slot, " ثبت گردید."),
            "appointments", v_new_id
        );

    COMMIT;

    SET p_result_code=0; SET p_result_msg="نوبت با موفقیت ثبت شد"; SET p_appointment_id=v_new_id;

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `MarkNotificationDelivered` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `MarkNotificationDelivered`(
    IN p_outbox_id           BIGINT UNSIGNED,
    IN p_user_id             BIGINT UNSIGNED,
    IN p_user_phone          VARCHAR(11),
    IN p_type                VARCHAR(30),
    IN p_title               VARCHAR(200),
    IN p_body                TEXT,
    IN p_related_entity_type VARCHAR(60),
    IN p_related_entity_id   BIGINT UNSIGNED
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    START TRANSACTION;

        UPDATE notification_outbox
        SET    status       = 'delivered',
               processed_at = NOW()
        WHERE  id           = p_outbox_id;

        INSERT INTO notifications (
            user_id,       user_phone,
            type,          title,         body,
            related_entity_type,           related_entity_id,
            is_read,       created_at
        ) VALUES (
            p_user_id,     p_user_phone,
            p_type,        p_title,       p_body,
            p_related_entity_type,         p_related_entity_id,
            0,             NOW()
        );

    COMMIT;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `MarkNotificationFailed` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `MarkNotificationFailed`(
    IN p_outbox_id           BIGINT UNSIGNED,
    IN p_max_attempts        TINYINT UNSIGNED,
    IN p_retry_delay_seconds INT UNSIGNED
)
BEGIN
    DECLARE v_attempt_count TINYINT UNSIGNED DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    START TRANSACTION;

        SELECT attempt_count
        INTO   v_attempt_count
        FROM   notification_outbox
        WHERE  id = p_outbox_id
        FOR UPDATE;

        IF v_attempt_count >= p_max_attempts THEN
            UPDATE notification_outbox
            SET    status       = 'failed',
                   processed_at = NOW()
            WHERE  id           = p_outbox_id;
        ELSE
            UPDATE notification_outbox
            SET    status        = 'pending',
                   next_retry_at = DATE_ADD(NOW(), INTERVAL p_retry_delay_seconds SECOND)
            WHERE  id            = p_outbox_id;
        END IF;

    COMMIT;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `VerifyBusiness` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_persian_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `VerifyBusiness`(
    IN  p_verification_id BIGINT UNSIGNED,
    IN  p_admin_user_id   BIGINT UNSIGNED,
    IN  p_new_status      VARCHAR(20),
    IN  p_admin_note      VARCHAR(1000),
    OUT p_result_code     TINYINT,
    OUT p_result_msg      VARCHAR(300)
)
VerifyBusiness: BEGIN
    DECLARE v_business_id  BIGINT UNSIGNED  DEFAULT NULL;
    DECLARE v_biz_owner_id BIGINT UNSIGNED  DEFAULT NULL;
    DECLARE v_biz_name     VARCHAR(200)     DEFAULT NULL;
    DECLARE v_owner_phone  VARCHAR(11)      DEFAULT NULL;
    DECLARE v_cur_status   VARCHAR(20)      DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_result_code = 99;
        SET p_result_msg  = "خطای داخلی سرور";
    END;

    START TRANSACTION;

        SELECT business_id, status, business_name, owner_user_id, owner_phone
        INTO   v_business_id, v_cur_status, v_biz_name, v_biz_owner_id, v_owner_phone
        FROM   business_verification
        WHERE  id = p_verification_id
        FOR UPDATE;

        IF v_cur_status IS NULL THEN
            ROLLBACK;
            SET p_result_code = 1;
            SET p_result_msg  = "درخواست تایید یافت نشد";
            LEAVE VerifyBusiness;
        END IF;

        IF v_cur_status != "در انتظار" THEN
            ROLLBACK;
            SET p_result_code = 2;
            SET p_result_msg  = "این درخواست قبلاً بررسی شده است";
            LEAVE VerifyBusiness;
        END IF;

        UPDATE business_verification
        SET    status      = p_new_status,
               admin_note  = p_admin_note,
               reviewed_by = p_admin_user_id
        WHERE  id          = p_verification_id;

        IF p_new_status = "تایید شده" THEN
            UPDATE businesses
            SET    is_verified = 1, is_active = 1
            WHERE  id = v_business_id;

            CALL WriteNotificationOutbox(
                CONCAT("biz_verified_", p_verification_id),
                v_biz_owner_id, v_owner_phone,
                "تایید_کسب‌وکار", "کسب‌وکار شما تایید شد",
                CONCAT("کسب‌وکار «", v_biz_name, "» توسط ادمین تایید و فعال شد."),
                "businesses", v_business_id
            );
        ELSE
            CALL WriteNotificationOutbox(
                CONCAT("biz_rejected_", p_verification_id),
                v_biz_owner_id, v_owner_phone,
                "رد_کسب‌وکار", "کسب‌وکار شما تایید نشد",
                CONCAT("کسب‌وکار «", v_biz_name, "» تایید نشد. دلیل: ", COALESCE(p_admin_note, "ذکر نشده")),
                "business_verification", p_verification_id
            );
        END IF;

    COMMIT;

    SET p_result_code = 0;
    SET p_result_msg  = CONCAT("وضعیت کسب‌وکار به «", p_new_status, "» تغییر یافت");

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 DROP PROCEDURE IF EXISTS `WriteAuditLog` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `WriteAuditLog`(
    IN p_entity_type  VARCHAR(60),
    IN p_entity_id    BIGINT UNSIGNED,
    IN p_action       VARCHAR(30),
    IN p_description  TEXT,
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    INSERT INTO audit_log (entity_type, entity_id, action, description, performed_by)
    VALUES (p_entity_type, p_entity_id, p_action, p_description, p_performed_by);
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!50003 DROP PROCEDURE IF EXISTS `WriteNotificationOutbox` */;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO' */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `WriteNotificationOutbox`(
    IN p_idempotency_key     VARCHAR(120),
    IN p_user_id             BIGINT UNSIGNED,
    IN p_user_phone          VARCHAR(11),
    IN p_type                VARCHAR(30),
    IN p_title               VARCHAR(200),
    IN p_body                TEXT,
    IN p_related_entity_type VARCHAR(60),
    IN p_related_entity_id   BIGINT UNSIGNED
)
BEGIN
    INSERT INTO notification_outbox (
        idempotency_key,
        user_id,       user_phone,
        type,          title,        body,
        related_entity_type,         related_entity_id,
        status,        attempt_count,
        next_retry_at
    ) VALUES (
        p_idempotency_key,
        p_user_id,     p_user_phone,
        p_type,        p_title,      p_body,
        p_related_entity_type,       p_related_entity_id,
        'pending',     0,
        NULL
    )
    ON DUPLICATE KEY UPDATE id = id;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
ALTER DATABASE `servora` CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-19 19:48:45
