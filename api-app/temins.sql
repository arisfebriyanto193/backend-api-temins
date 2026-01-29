-- MySQL dump 10.13  Distrib 8.0.41, for Linux (x86_64)
--
-- Host: localhost    Database: temins
-- ------------------------------------------------------
-- Server version	8.0.41-0ubuntu0.20.04.1

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

--
-- Table structure for table `device_settings`
--

DROP TABLE IF EXISTS `device_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_unique_id` varchar(50) NOT NULL,
  `parameter_name` varchar(50) NOT NULL,
  `mqtt_topic` varchar(100) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `display_order` int DEFAULT '0',
  `is_visible` tinyint(1) DEFAULT '1',
  `tinggi_sensor` varchar(255) DEFAULT NULL,
  `category` varchar(200) DEFAULT 'sensor',
  PRIMARY KEY (`id`),
  KEY `device_unique_id` (`device_unique_id`)
) ENGINE=InnoDB AUTO_INCREMENT=991 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_settings`
--

LOCK TABLES `device_settings` WRITE;
/*!40000 ALTER TABLE `device_settings` DISABLE KEYS */;
INSERT INTO `device_settings` VALUES (216,'0015','Suhu udara ','temins_iot/0015/data/su','°C',1,1,'','sensor'),(217,'0015','Kelembapan Udara','temins_iot/0015/data/ku','%',2,1,'','sensor'),(218,'0015','Kecepatan angin','temins_iot/0015/data/ka','m/s',3,1,'','sensor'),(219,'0015','Arah Angin','temins_iot/0015/data/aa','°',4,1,'','sensor'),(220,'0015','Radiasi Matahari','temins_iot/0015/data/rm','W/m',5,1,'','sensor'),(221,'0015','Tekanan Udara','temins_iot/0015/data/tu','hpa',6,1,'','sensor'),(222,'0015','Curah Hujan Berjalan ','temins_iot/0015/data/ch','mm',7,1,'','sensor'),(223,'0015','Curah Hujan 1 Jam','temins_iot/0015/data/chj','mm',8,0,'','sensor'),(224,'0015','Curah Hujan Kemarin','temins_iot/0015/data/chk','mm',9,0,'','sensor'),(225,'0015','Baterai','temins_iot/0015/data/tsp','V',10,1,'','sensor'),(226,'0015','Tegangan','temins_iot/0015/data/tsp','V',100,1,'','power'),(227,'0015','Arus Charging','temins_iot/0015/data/ac','mA',101,1,'','power'),(228,'0021','Suhu udara ','temins_iot/0021/data/su','°C',1,1,'','sensor'),(229,'0021','Kelembapan Udara','temins_iot/0021/data/ku','%',2,1,'','sensor'),(230,'0021','Kecepatan angin','temins_iot/0021/data/ka','m/s',3,1,'','sensor'),(231,'0021','Arah Angin','temins_iot/0021/data/aa','°',4,1,'','sensor'),(232,'0021','Radiasi Matahari','temins_iot/0021/data/rm','W/m',5,1,'','sensor'),(233,'0021','Tekanan Udara','temins_iot/0021/data/tu','hpa',6,1,'','sensor'),(234,'0021','Curah Hujan Berjalan ','temins_iot/0021/data/ch','mm',7,1,'','sensor'),(235,'0021','Curah Hujan 1 Jam','temins_iot/0021/data/chj','mm',8,0,'','sensor'),(236,'0021','Curah Hujan Kemarin','temins_iot/0021/data/chk','mm',9,0,'','sensor'),(237,'0021','Baterai','temins_iot/0021/data/tsp','V',10,1,'','sensor'),(238,'0021','Tegangan','temins_iot/0021/data/tsp','V',100,1,'','power'),(239,'0021','Arus Charging','temins_iot/0021/data/ac','mA',101,1,'','power'),(240,'0023','Suhu udara ','temins_iot/0023/data/su','°C',1,1,'','sensor'),(241,'0023','Kelembapan Udara','temins_iot/0023/data/ku','%',2,1,'','sensor'),(242,'0023','Kecepatan angin','temins_iot/0023/data/ka','m/s',3,1,'','sensor'),(243,'0023','Arah Angin','temins_iot/0023/data/aa','°',4,1,'','sensor'),(244,'0023','Radiasi Matahari','temins_iot/0023/data/rm','W/m',5,1,'','sensor'),(245,'0023','Tekanan Udara','temins_iot/0023/data/tu','hpa',6,1,'','sensor'),(246,'0023','Curah Hujan Berjalan ','temins_iot/0023/data/ch','mm',7,1,'','sensor'),(247,'0023','Curah Hujan 1 Jam','temins_iot/0023/data/chj','mm',8,1,'','sensor'),(248,'0023','Curah Hujan Kemarin','temins_iot/0023/data/chk','mm',9,1,'','sensor'),(249,'0023','Baterai','temins_iot/0023/data/tsp','V',10,1,'','sensor'),(250,'0023','Tegangan','temins_iot/0023/data/tsp','V',100,1,'','power'),(251,'0023','Arus Charging','temins_iot/0023/data/ac','mA',101,1,'','power'),(252,'0026','Suhu udara ','temins_iot/0026/data/su','°C',1,1,'','sensor'),(253,'0026','Kelembapan Udara','temins_iot/0026/data/ku','%',2,1,'','sensor'),(254,'0026','Kecepatan angin','temins_iot/0026/data/ka','m/s',3,1,'','sensor'),(255,'0026','Arah Angin','temins_iot/0026/data/aa','°',4,1,'','sensor'),(256,'0026','Radiasi Matahari','temins_iot/0026/data/rm','W/m',5,1,'','sensor'),(257,'0026','Tekanan Udara','temins_iot/0026/data/tu','hpa',6,1,'','sensor'),(258,'0026','Curah Hujan Berjalan ','temins_iot/0026/data/ch','mm',7,1,'','sensor'),(259,'0026','Curah Hujan 1 Jam','temins_iot/0026/data/chj','mm',8,0,'','sensor'),(260,'0026','Curah Hujan Kemarin','temins_iot/0026/data/chk','mm',9,0,'','sensor'),(261,'0026','Baterai','temins_iot/0026/data/tsp','V',10,1,'','sensor'),(262,'0026','Tegangan','temins_iot/0026/data/tsp','V',100,1,'','power'),(263,'0026','Arus Charging','temins_iot/0026/data/ac','mA',101,1,'','power'),(264,'0024','Suhu udara ','temins_iot/0024/data/su','°C',1,1,'','sensor'),(265,'0024','Kelembapan Udara','temins_iot/0024/data/ku','%',2,1,'','sensor'),(266,'0024','Kecepatan angin','temins_iot/0024/data/ka','m/s',3,1,'','sensor'),(267,'0024','Arah Angin','temins_iot/0024/data/aa','°',4,1,'','sensor'),(268,'0024','Radiasi Matahari','temins_iot/0024/data/rm','W/m',5,1,'','sensor'),(269,'0024','Tekanan Udara','temins_iot/0024/data/tu','hpa',6,1,'','sensor'),(270,'0024','Curah Hujan Berjalan ','temins_iot/0024/data/ch','mm',7,1,'','sensor'),(273,'0024','Baterai','temins_iot/0024/data/tsp','V',8,1,'','sensor'),(274,'0024','Tegangan','temins_iot/0024/data/tsp','V',100,1,'','power'),(275,'0024','Arus Charging','temins_iot/0024/data/ac','mA',101,1,'','power'),(276,'00122','Level Air','temins_iot/00122/data/lvl','cm',1,1,NULL,'sensor'),(277,'00122','Debit','temins_iot/00122/data/flow','L/m',2,1,NULL,'sensor'),(278,'00122','Tegangan','temins_iot/00122/data/tsp','V',100,1,NULL,'power'),(279,'00122','Arus Charging','temins_iot/00122/data/ac','mA',101,1,NULL,'power'),(280,'4444','ketinggin air','qqq/eee/rr','cm',1,1,NULL,'sensor'),(281,'4444','Tegangan','temins_iot/4444/data/tsp','V',100,1,NULL,'power'),(282,'4444','Arus Charging','temins_iot/4444/data/ac','mA',101,1,NULL,'power'),(283,'0031','Suhu udara ','temins_iot/0031/data/su','°C',1,1,NULL,'sensor'),(284,'0031','Kelembapan Udara','temins_iot/0031/data/ku','%',2,0,NULL,'sensor'),(285,'0031','Kecepatan angin','temins_iot/0031/data/ka','m/s',3,0,NULL,'sensor'),(286,'0031','Arah Angin','temins_iot/0031/data/aa','°',4,0,NULL,'sensor'),(287,'0031','Radiasi Matahari','temins_iot/0031/data/rm','W/m',5,0,NULL,'sensor'),(288,'0031','Tekanan Udara','temins_iot/0031/data/tu','hpa',6,0,NULL,'sensor'),(289,'0031','Curah Hujan Berjalan ','temins_iot/0031/data/ch','mm',7,0,NULL,'sensor'),(290,'0031','Curah Hujan 1 Jam','temins_iot/0031/data/chj','mm',8,1,NULL,'sensor'),(291,'0031','Curah Hujan Kemarin','temins_iot/0031/data/chk','mm',9,1,NULL,'sensor'),(292,'0031','Baterai','temins_iot/0031/data/tsp','V',10,1,NULL,'sensor'),(293,'0031','Tegangan','temins_iot/0031/data/tsp','V',100,1,NULL,'power'),(294,'0031','Arus Charging','temins_iot/0031/data/ac','mA',101,1,NULL,'power'),(297,'0014','Tegangan','temins_iot/0014/data/tsp','V',100,1,NULL,'power'),(298,'0014','Arus Charging','temins_iot/0014/data/ac','mA',101,1,NULL,'power'),(299,'0013','Tegangan','temins_iot/0013/data/tsp','V',100,1,NULL,'power'),(300,'0013','Arus Charging','temins_iot/0013/data/ac','mA',101,1,NULL,'power'),(301,'0019','Suhu udara ','temins_iot/0019/data/su','°C',1,1,NULL,'sensor'),(302,'0019','Kelembapan Udara','temins_iot/0019/data/ku','%',2,1,NULL,'sensor'),(303,'0019','Kecepatan angin','temins_iot/0019/data/ka','m/s',3,1,NULL,'sensor'),(304,'0019','Arah Angin','temins_iot/0019/data/aa','°',4,1,NULL,'sensor'),(305,'0019','Radiasi Matahari','temins_iot/0019/data/rm','W/m',5,1,NULL,'sensor'),(306,'0019','Tekanan Udara','temins_iot/0019/data/tu','hpa',6,1,NULL,'sensor'),(307,'0019','Curah Hujan Berjalan ','temins_iot/0019/data/ch','mm',7,1,NULL,'sensor'),(308,'0019','Curah Hujan 1 Jam','temins_iot/0019/data/chj','mm',8,0,NULL,'sensor'),(309,'0019','Curah Hujan Kemarin','temins_iot/0019/data/chk','mm',9,0,NULL,'sensor'),(310,'0019','Baterai','temins_iot/0019/data/tsp','V',10,1,NULL,'sensor'),(311,'0019','Tegangan','temins_iot/0019/data/tsp','V',100,1,NULL,'power'),(312,'0019','Arus Charging','temins_iot/0019/data/ac','mA',101,1,NULL,'power'),(313,'0025','Suhu udara ','temins_iot/0025/data/su','°C',1,1,NULL,'sensor'),(314,'0025','Kelembapan Udara','temins_iot/0025/data/ku','%',2,1,NULL,'sensor'),(315,'0025','Kecepatan angin','temins_iot/0025/data/ka','m/s',3,1,NULL,'sensor'),(316,'0025','Arah Angin','temins_iot/0025/data/aa','°',4,1,NULL,'sensor'),(317,'0025','Radiasi Matahari','temins_iot/0025/data/rm','W/m',5,1,NULL,'sensor'),(318,'0025','Tekanan Udara','temins_iot/0025/data/tu','hpa',6,1,NULL,'sensor'),(319,'0025','Curah Hujan Berjalan ','temins_iot/0025/data/ch','mm',7,1,NULL,'sensor'),(320,'0025','Curah Hujan 1 Jam','temins_iot/0025/data/chj','mm',8,1,NULL,'sensor'),(321,'0025','Curah Hujan Kemarin','temins_iot/0025/data/chk','mm',9,1,NULL,'sensor'),(322,'0025','Baterai','temins_iot/0025/data/tsp','V',10,1,NULL,'sensor'),(323,'0025','Tegangan','temins_iot/0025/data/tsp','V',100,1,NULL,'power'),(324,'0025','Arus Charging','temins_iot/0025/data/ac','mA',101,1,NULL,'power'),(325,'0027','Suhu udara ','temins_iot/0027/data/su','°C',1,1,'','sensor'),(326,'0027','Kelembapan Udara','temins_iot/0027/data/ku','%',2,1,'','sensor'),(327,'0027','Kecepatan angin','temins_iot/0027/data/ka','m/s',3,1,'','sensor'),(328,'0027','Arah Angin','temins_iot/0027/data/aa','°',4,1,'','sensor'),(329,'0027','Radiasi Matahari','temins_iot/0027/data/rm','W/m',5,1,'','sensor'),(330,'0027','Tekanan Udara','temins_iot/0027/data/tu','hpa',6,1,'','sensor'),(331,'0027','Curah Hujan Berjalan ','temins_iot/0027/data/ch','mm',7,1,'','sensor'),(332,'0027','Curah Hujan 1 Jam','temins_iot/0027/data/chj','mm',8,0,'','sensor'),(333,'0027','Curah Hujan Kemarin','temins_iot/0027/data/chk','mm',9,0,'','sensor'),(334,'0027','Baterai','temins_iot/0027/data/tsp','V',10,1,'','sensor'),(335,'0027','Tegangan','temins_iot/0027/data/tsp','V',100,1,'','power'),(336,'0027','Arus Charging','temins_iot/0027/data/ac','mA',101,1,'','power'),(349,'0028','Suhu udara ','temins_iot/0028/data/su2','°C',1,1,'','sensor'),(350,'0028','Kelembapan Udara','temins_iot/0028/data/ku2','%',2,1,'','sensor'),(351,'0028','Kecepatan angin','temins_iot/0028/data/ka','m/s',3,1,'','sensor'),(352,'0028','Arah Angin','temins_iot/0028/data/aa','°',4,1,'','sensor'),(353,'0028','Radiasi Matahari','temins_iot/0028/data/rm','W/m',5,1,'','sensor'),(354,'0028','Tekanan Udara','temins_iot/0028/data/tu','hpa',6,1,'','sensor'),(358,'0028','Baterai','temins_iot/0028/data/tsp','V',7,1,'','sensor'),(359,'0028','Tegangan','temins_iot/0028/data/tsp','V',100,1,'','power'),(360,'0028','Arus Charging','temins_iot/0028/data/ac','mA',101,1,'','power'),(361,'0029','Suhu udara ','temins_iot/0029/data/su','°C',1,1,NULL,'sensor'),(362,'0029','Kelembapan Udara','temins_iot/0029/data/ku','%',2,1,NULL,'sensor'),(363,'0029','Kecepatan angin','temins_iot/0029/data/ka','m/s',3,1,NULL,'sensor'),(364,'0029','Arah Angin','temins_iot/0029/data/aa','°',4,1,NULL,'sensor'),(365,'0029','Radiasi Matahari','temins_iot/0029/data/rm','W/m',5,1,NULL,'sensor'),(366,'0029','Tekanan Udara','temins_iot/0029/data/tu','hpa',6,1,NULL,'sensor'),(367,'0029','Curah Hujan Berjalan ','temins_iot/0029/data/ch','mm',7,1,NULL,'sensor'),(368,'0029','Curah Hujan 1 Jam','temins_iot/0029/data/chj','mm',8,1,NULL,'sensor'),(369,'0029','Curah Hujan Kemarin','temins_iot/0029/data/chk','mm',9,1,NULL,'sensor'),(370,'0029','Baterai','temins_iot/0029/data/tsp','V',10,1,NULL,'sensor'),(371,'0029','Tegangan','temins_iot/0029/data/tsp','V',100,1,NULL,'power'),(372,'0029','Arus Charging','temins_iot/0029/data/ac','mA',101,1,NULL,'power'),(373,'0030','Suhu udara ','temins_iot/0030/data/su','°C',1,1,NULL,'sensor'),(374,'0030','Kelembapan Udara','temins_iot/0030/data/ku','%',2,1,NULL,'sensor'),(375,'0030','Kecepatan angin','temins_iot/0030/data/ka','m/s',3,1,NULL,'sensor'),(376,'0030','Arah Angin','temins_iot/0030/data/aa','°',4,1,NULL,'sensor'),(377,'0030','Radiasi Matahari','temins_iot/0030/data/rm','W/m',5,1,NULL,'sensor'),(378,'0030','Tekanan Udara','temins_iot/0030/data/tu','hpa',6,1,NULL,'sensor'),(379,'0030','Curah Hujan Berjalan ','temins_iot/0030/data/ch','mm',7,1,NULL,'sensor'),(380,'0030','Curah Hujan 1 Jam','temins_iot/0030/data/chj','mm',8,1,NULL,'sensor'),(381,'0030','Curah Hujan Kemarin','temins_iot/0030/data/chk','mm',9,1,NULL,'sensor'),(382,'0030','Baterai','temins_iot/0030/data/tsp','V',10,1,NULL,'sensor'),(383,'0030','Tegangan','temins_iot/0030/data/tsp','V',100,1,NULL,'power'),(384,'0030','Arus Charging','temins_iot/0030/data/ac','mA',101,1,NULL,'power'),(385,'0031','Suhu udara ','temins_iot/0031/data/su','°C',1,0,NULL,'sensor'),(386,'0031','Kelembapan Udara','temins_iot/0031/data/ku','%',2,1,NULL,'sensor'),(387,'0031','Kecepatan angin','temins_iot/0031/data/ka','m/s',3,1,NULL,'sensor'),(388,'0031','Arah Angin','temins_iot/0031/data/aa','°',4,1,NULL,'sensor'),(389,'0031','Radiasi Matahari','temins_iot/0031/data/rm','W/m',5,1,NULL,'sensor'),(390,'0031','Tekanan Udara','temins_iot/0031/data/tu','hpa',6,1,NULL,'sensor'),(391,'0031','Curah Hujan Berjalan ','temins_iot/0031/data/ch','mm',7,1,NULL,'sensor'),(392,'0031','Curah Hujan 1 Jam','temins_iot/0031/data/chj','mm',8,0,NULL,'sensor'),(393,'0031','Curah Hujan Kemarin','temins_iot/0031/data/chk','mm',9,0,NULL,'sensor'),(394,'0031','Baterai','temins_iot/0031/data/tsp','V',10,0,NULL,'sensor'),(395,'0031','Tegangan','temins_iot/0031/data/tsp','V',100,1,NULL,'power'),(396,'0031','Arus Charging','temins_iot/0031/data/ac','mA',101,1,NULL,'power'),(397,'0032','Suhu udara ','temins_iot/0032/data/su','°C',1,1,'','sensor'),(398,'0032','Kelembapan Udara','temins_iot/0032/data/ku','%',2,1,'','sensor'),(399,'0032','Kecepatan angin','temins_iot/0032/data/ka','m/s',3,1,'','sensor'),(400,'0032','Arah Angin','temins_iot/0032/data/aa','°',4,1,'','sensor'),(401,'0032','Radiasi Matahari','temins_iot/0032/data/rm','W/m',5,1,'','sensor'),(402,'0032','Tekanan Udara','temins_iot/0032/data/tu','hpa',6,1,'','sensor'),(403,'0032','Curah Hujan Berjalan ','temins_iot/0032/data/ch','mm',7,1,'','sensor'),(404,'0032','Curah Hujan 1 Jam','temins_iot/0032/data/chj','mm',8,0,'','sensor'),(405,'0032','Curah Hujan Kemarin','temins_iot/0032/data/chk','mm',9,0,'','sensor'),(406,'0032','Baterai','temins_iot/0032/data/tsp','V',10,1,'','sensor'),(407,'0032','Tegangan','temins_iot/0032/data/tsp','V',100,1,'','power'),(408,'0032','Arus Charging','temins_iot/0032/data/ac','mA',101,1,'','power'),(409,'0033','Suhu udara ','temins_iot/0033/data/su','°C',1,1,'','sensor'),(410,'0033','Kelembapan Udara','temins_iot/0033/data/ku','%',2,1,'','sensor'),(411,'0033','Kecepatan angin','temins_iot/0033/data/ka','m/s',3,1,'','sensor'),(412,'0033','Arah Angin','temins_iot/0033/data/aa','°',4,1,'','sensor'),(413,'0033','Radiasi Matahari','temins_iot/0033/data/rm','W/m',5,1,'','sensor'),(414,'0033','Tekanan Udara','temins_iot/0033/data/tu','hpa',6,1,'','sensor'),(415,'0033','Curah Hujan Berjalan ','temins_iot/0033/data/ch','mm',7,1,'','sensor'),(416,'0033','Curah Hujan 1 Jam','temins_iot/0033/data/chj','mm',8,0,'','sensor'),(417,'0033','Curah Hujan Kemarin','temins_iot/0033/data/chk','mm',9,0,'','sensor'),(418,'0033','Baterai','temins_iot/0033/data/tsp','V',10,1,'','sensor'),(419,'0033','Tegangan','temins_iot/0033/data/tsp','V',100,1,'','power'),(420,'0033','Arus Charging','temins_iot/0033/data/ac','mA',101,1,'','power'),(421,'0034','Suhu udara ','temins_iot/0034/data/su','°C',1,1,NULL,'sensor'),(422,'0034','Kelembapan Udara','temins_iot/0034/data/ku','%',2,1,NULL,'sensor'),(423,'0034','Kecepatan angin','temins_iot/0034/data/ka','m/s',3,1,NULL,'sensor'),(424,'0034','Arah Angin','temins_iot/0034/data/aa','°',4,1,NULL,'sensor'),(425,'0034','Radiasi Matahari','temins_iot/0034/data/rm','W/m',5,1,NULL,'sensor'),(426,'0034','Tekanan Udara','temins_iot/0034/data/tu','hpa',6,1,NULL,'sensor'),(427,'0034','Curah Hujan Berjalan ','temins_iot/0034/data/ch','mm',7,1,NULL,'sensor'),(428,'0034','Curah Hujan 1 Jam','temins_iot/0034/data/chj','mm',8,1,NULL,'sensor'),(429,'0034','Curah Hujan Kemarin','temins_iot/0034/data/chk','mm',9,1,NULL,'sensor'),(430,'0034','Baterai','temins_iot/0034/data/tsp','V',10,1,NULL,'sensor'),(431,'0034','Tegangan','temins_iot/0034/data/tsp','V',100,1,NULL,'power'),(432,'0034','Arus Charging','temins_iot/0034/data/ac','mA',101,1,NULL,'power'),(433,'0040','Tegangan','temins_iot/0040/data/tsp','V',100,1,'','power'),(434,'0040','Arus Charging','temins_iot/0040/data/ac','mA',101,1,'','power'),(435,'0035','Suhu udara ','temins_iot/0035/data/su','°C',1,1,NULL,'sensor'),(436,'0035','Kelembapan Udara','temins_iot/0035/data/ku','%',2,1,NULL,'sensor'),(437,'0035','Kecepatan angin','temins_iot/0035/data/ka','m/s',3,1,NULL,'sensor'),(438,'0035','Arah Angin','temins_iot/0035/data/aa','°',4,1,NULL,'sensor'),(439,'0035','Radiasi Matahari','temins_iot/0035/data/rm','W/m',5,1,NULL,'sensor'),(440,'0035','Tekanan Udara','temins_iot/0035/data/tu','hpa',6,1,NULL,'sensor'),(441,'0035','Curah Hujan Berjalan ','temins_iot/0035/data/ch','mm',7,1,NULL,'sensor'),(442,'0035','Curah Hujan 1 Jam','temins_iot/0035/data/chj','mm',8,1,NULL,'sensor'),(443,'0035','Curah Hujan Kemarin','temins_iot/0035/data/chk','mm',9,1,NULL,'sensor'),(444,'0035','Baterai','temins_iot/0035/data/tsp','V',10,1,NULL,'sensor'),(445,'0035','Tegangan','temins_iot/0035/data/tsp','V',100,1,NULL,'power'),(446,'0035','Arus Charging','temins_iot/0035/data/ac','mA',101,1,NULL,'power'),(447,'0007','Suhu udara ','temins_iot/0007/data/su','°C',1,1,NULL,'sensor'),(448,'0007','Kelembapan Udara','temins_iot/0007/data/ku','%',2,1,NULL,'sensor'),(449,'0007','Kecepatan angin','temins_iot/0007/data/ka','m/s',3,1,NULL,'sensor'),(450,'0007','Arah Angin','temins_iot/0007/data/aa','°',4,1,NULL,'sensor'),(451,'0007','Radiasi Matahari','temins_iot/0007/data/rm','W/m',5,1,NULL,'sensor'),(452,'0007','Tekanan Udara','temins_iot/0007/data/tu','hpa',6,1,NULL,'sensor'),(453,'0007','Curah Hujan Berjalan ','temins_iot/0007/data/ch','mm',7,1,NULL,'sensor'),(454,'0007','Curah Hujan 1 Jam','temins_iot/0007/data/chj','mm',8,1,NULL,'sensor'),(455,'0007','Curah Hujan Kemarin','temins_iot/0007/data/chk','mm',9,1,NULL,'sensor'),(456,'0007','Baterai','temins_iot/0007/data/tsp','V',10,1,NULL,'sensor'),(457,'0007','Tegangan','temins_iot/0007/data/tsp','V',100,1,NULL,'power'),(458,'0007','Arus Charging','temins_iot/0007/data/ac','mA',101,1,NULL,'power'),(459,'0050','Suhu Tanah','temins_iot/0050/data/soil_t','°C',1,1,NULL,'sensor'),(460,'0050','Lembab Tanah','temins_iot/0050/data/soil_h','%',2,1,NULL,'sensor'),(461,'0050','pH','temins_iot/0050/data/ph','pH',3,1,NULL,'sensor'),(462,'0050','Tegangan','temins_iot/0050/data/tsp','V',100,1,NULL,'power'),(463,'0050','Arus Charging','temins_iot/0050/data/ac','mA',101,1,NULL,'power'),(464,'0100','Suhu udara ','temins_iot/0100/data/su','°C',1,1,NULL,'sensor'),(465,'0100','Kelembapan Udara','temins_iot/0100/data/ku','%',2,1,NULL,'sensor'),(466,'0100','Kecepatan angin','temins_iot/0100/data/ka','m/s',3,1,NULL,'sensor'),(467,'0100','Arah Angin','temins_iot/0100/data/aa','°',4,1,NULL,'sensor'),(468,'0100','Radiasi Matahari','temins_iot/0100/data/rm','W/m',5,1,NULL,'sensor'),(469,'0100','Tekanan Udara','temins_iot/0100/data/tu','hpa',6,1,NULL,'sensor'),(470,'0100','Curah Hujan Berjalan ','temins_iot/0100/data/ch','mm',7,1,NULL,'sensor'),(471,'0100','Curah Hujan 1 Jam','temins_iot/0100/data/chj','mm',8,1,NULL,'sensor'),(472,'0100','Curah Hujan Kemarin','temins_iot/0100/data/chk','mm',9,1,NULL,'sensor'),(473,'0100','Baterai','temins_iot/0100/data/tsp','V',10,1,NULL,'sensor'),(474,'0100','Tegangan','temins_iot/0100/data/tsp','V',100,1,NULL,'power'),(475,'0100','Arus Charging','temins_iot/0100/data/ac','mA',101,1,NULL,'power'),(531,'4452','Suhu Tanah','temins_iot/4452/data/st','°C',1,1,'','sensor'),(532,'4452','Kelembapan Tanah','temins_iot/4452/data/kt','%',2,1,'','sensor'),(533,'4452','pH Tanah','temins_iot/4452/data/pht','pH',3,1,'','sensor'),(534,'4452','ec','temins_iot/4452/data/ec','n',4,1,'','sensor'),(535,'4452','tds','temins_iot/4452/data/tds','n',5,1,'','sensor'),(536,'4452','kdt','temins_iot/4452/data/kdt','n',6,1,'','sensor'),(537,'4452','nitrogen','temins_iot/4452/data/n','no',7,1,'','sensor'),(538,'4452','Phosphor','temins_iot/4452/data/p','nyyy',8,1,'','sensor'),(539,'4452','Kalium','temins_iot/4452/data/k','nyy',9,1,'','sensor'),(540,'4452','garam','temins_iot/4452/data/g','g',10,0,'','sensor'),(541,'4452','Baterai','temins_iot/4452/data/tsp','V',11,1,'','sensor'),(542,'4452','Arus charging','temins_iot/4452/data/ac','mA',12,0,'','sensor'),(543,'4452','Daya ','temins_iot/4452/data/da','w',13,0,'','sensor'),(544,'4452','Tegangan','temins_iot/4452/data/tsp','V',100,1,'','power'),(545,'4452','Arus Charging','temins_iot/4452/data/ac','mA',101,1,'','power'),(573,'3345','tinggi air mm','temins_iot/3345/data/tum','mm',1,1,'678','sensor'),(574,'3345','tinggi air cm','temins_iot/3345/data/tuc','cm',2,1,'678','sensor'),(575,'3345','tinggi air dalam cm','temins_iot/3345/data/tac','u',3,1,'678','sensor'),(576,'3345','tinggi air dalam mm','temins_iot/3345/data/tam','u',4,1,'678','sensor'),(577,'3345','batre','temins_iot/3345/data/bat','V',5,1,'678','sensor'),(578,'3345','Tegangan','temins_iot/3345/data/tsp','V',100,1,'678','power'),(579,'3345','Arus Charging','temins_iot/3345/data/ac','mA',101,1,'678','power'),(620,'0022','Suhu Udara','temins_iot/0022/data/su','°C',1,1,'','sensor'),(621,'0022','Kelembapan Udara','temins_iot/0022/data/ku','%',2,1,'','sensor'),(622,'0022','Kecepatan Angin','temins_iot/0022/data/ka','m/s',3,1,'','sensor'),(623,'0022','Arah Angin','temins_iot/0022/data/aa','°',4,1,'','sensor'),(624,'0022','Radiasi Matahari','temins_iot/0022/data/rm','W/m²',5,1,'','sensor'),(625,'0022','Tekanan Udara','temins_iot/0022/data/tu','hPa',6,1,'','sensor'),(626,'0022','Curah Hujan Berjalan','temins_iot/0022/data/ch','mm',7,1,'','sensor'),(627,'0022','Baterai','temins_iot/0022/data/tsp','V',8,1,'','sensor'),(629,'0022','Arus Charging','temins_iot/0022/data/ac','mA',9,0,'','sensor'),(630,'0022','Tegangan','temins_iot/0022/data/tsp','V',100,1,'','power'),(631,'0022','Arus Charging','temins_iot/0022/data/ac','mA',101,1,'','power'),(777,'3345','hasil(cm)','temins_iot/3345/data/cmm','cm',6,0,'678','sensor'),(817,'0028','daya','temins_iot/0028/data/da','waat',9,0,'','power'),(896,'0098','udara mm','temins_iot/0098/data/tum','cm',1,1,'20003','sensor'),(897,'0098','udara cm','temins_iot/0098/data/tuc','L/m',2,1,'20003','sensor'),(898,'0098','sensor3','temins_iot/0098/data/tac','u',3,1,'20003','sensor'),(899,'0098','sensor4','temins_iot/0098/data/tam','u',4,1,'20003','sensor'),(900,'0098','batre','temins_iot/0098/data/bat','V',5,1,'20003','sensor'),(901,'0098','Tegangan','temins_iot/0098/data/tsp','V',100,1,'20003','power'),(902,'0098','Arus Charging','temins_iot/0098/data/ac','mA',101,1,'20003','power'),(903,'0098','daya','temins_iot/0098/data/da','watt',101,1,'20003','power'),(904,'0098','tuc','config','0',0,0,'20003','config'),(905,'0012','udara mm','temins_iot/0012/data/tum','cm',1,1,'100','sensor'),(906,'0012','udara cm','temins_iot/0012/data/tuc','L/m',2,1,'100','sensor'),(907,'0012','sensor3','temins_iot/0012/data/tac','u',3,1,'100','sensor'),(908,'0012','sensor4','temins_iot/0012/data/tam','u',4,1,'100','sensor'),(909,'0012','batre','temins_iot/0012/data/tsp','V',5,1,'100','sensor'),(910,'0012','Tegangan','temins_iot/0012/data/tsp','V',100,1,'100','power'),(911,'0012','Arus Charging','temins_iot/0012/data/ac','mA',101,1,'100','power'),(912,'0012','daya','temins_iot/0012/data/da','watt',101,1,'100','power'),(913,'0012','ta','config','0',0,0,'100','config'),(914,'0012','tinggi air','temins_iot/0012/data/ta','cm',6,1,'100','sensor'),(915,'0011','Suhu Udara','temins_iot/0011/data/su','°C',1,1,'','sensor'),(916,'0011','Kelembapan Udara','temins_iot/0011/data/ku','%',2,1,'','sensor'),(917,'0011','Kecepatan Angin','temins_iot/0011/data/ka','m/s',3,1,'','sensor'),(918,'0011','Arah Angin','temins_iot/0011/data/aa','°',4,1,'','sensor'),(919,'0011','Radiasi Matahari','temins_iot/0011/data/rm','W/m²',5,1,'','sensor'),(920,'0011','Tekanan Udara','temins_iot/0011/data/tu','hPa',6,1,'','sensor'),(921,'0011','Curah Hujan Berjalan','temins_iot/0011/data/ch','mm',7,1,'','sensor'),(922,'0011','Baterai','temins_iot/0011/data/tsp','V',8,1,'','sensor'),(923,'0011','Tegangan','temins_iot/0011/data/tsp','v',9,1,'','sensor'),(924,'0011','Arus Charging','temins_iot/0011/data/ac','mA',10,0,'','sensor'),(925,'0011','Tegangan','temins_iot/0011/data/tsp','V',100,1,'','power'),(926,'0011','Arus Charging','temins_iot/0011/data/ac','mA',101,1,'','power'),(927,'0011','daya','temins_iot/0011/data/da','watt',101,1,'','power'),(928,'0022','Daya','temins_iot/0022/data/da','watt',10,1,'','power'),(939,'0038','udara mm','temins_iot/0038/data/tum','cm',1,1,'975','sensor'),(940,'0038','Tinggi sensor','temins_iot/0038/data/tuc','cm',2,1,'975','sensor'),(941,'0038','Tinggi Air ','temins_iot/0038/data/tac','cm',3,0,'975','sensor'),(942,'0038','sensor4','temins_iot/0038/data/tam','u',4,1,'975','sensor'),(943,'0038','batre','temins_iot/0038/data/tsp','V',5,1,'975','sensor'),(944,'0038','Tegangan','temins_iot/0038/data/tsp','V',100,1,'975','power'),(945,'0038','Arus Charging','temins_iot/0038/data/ac','mA',101,1,'975','power'),(946,'0038','daya','temins_iot/0038/data/da','watt',101,1,'975','power'),(947,'0038','tuc','config','1',0,0,'975','config'),(957,'0038','tuc','jenis','1',0,0,'975','sumur'),(958,'0012','tuc','jenis','1',0,0,'100','tidak di ketahui'),(959,'0028','tuc','jenis','1',0,0,'','haloo'),(960,'0028','Curah Hujan Berjalan ','temins_iot/0028/data/ch','mm',8,1,'','sensor'),(961,'0098','tuc','jenis','1',0,0,'20003','sumur'),(962,'0033','tuc','jenis','1',0,0,'','haloo'),(963,'0027','tuc','jenis','1',0,0,'','sungai'),(964,'021','Suhu Udara','temins_iot/021/data/su','°C',1,1,NULL,'sensor'),(965,'021','Kelembapan Udara','temins_iot/021/data/ku','%',2,1,NULL,'sensor'),(966,'021','Kecepatan Angin','temins_iot/021/data/ka','m/s',3,1,NULL,'sensor'),(967,'021','Arah Angin','temins_iot/021/data/aa','°',4,1,NULL,'sensor'),(968,'021','Radiasi Matahari','temins_iot/021/data/rm','W/m²',5,1,NULL,'sensor'),(969,'021','Tekanan Udara','temins_iot/021/data/tu','hPa',6,1,NULL,'sensor'),(970,'021','Curah Hujan Berjalan','temins_iot/021/data/ch','mm',7,1,NULL,'sensor'),(971,'021','Baterai','temins_iot/021/data/tsp','V',8,1,NULL,'sensor'),(972,'021','Tegangan','temins_iot/021/data/tsp','v',9,1,NULL,'sensor'),(973,'021','Arus Charging','temins_iot/021/data/ac','mA',10,1,NULL,'sensor'),(974,'021','Tegangan','temins_iot/021/data/tsp','V',100,1,NULL,'power'),(975,'021','Arus Charging','temins_iot/021/data/ac','mA',101,1,NULL,'power'),(976,'021','daya','temins_iot/021/data/da','watt',101,1,NULL,'power'),(977,'0037','Suhu Udara','temins_iot/0037/data/su','°C',1,1,'','sensor'),(978,'0037','Kelembapan Udara','temins_iot/0037/data/ku','%',2,1,'','sensor'),(979,'0037','Kecepatan Angin','temins_iot/0037/data/ka','m/s',3,1,'','sensor'),(980,'0037','Arah Angin','temins_iot/0037/data/aa','°',4,1,'','sensor'),(981,'0037','Lux','temins_iot/0037/data/rm','lux',5,1,'','sensor'),(982,'0037','Tekanan Udara','temins_iot/0037/data/tu','hPa',6,1,'','sensor'),(983,'0037','Curah Hujan Berjalan','temins_iot/0037/data/ch','mm',7,1,'','sensor'),(984,'0037','Baterai','temins_iot/0037/data/tsp','V',8,1,'','sensor'),(985,'0037','Tegangan','temins_iot/0037/data/tsp','v',9,1,'','sensor'),(986,'0037','Arus Charging','temins_iot/0037/data/ac','mA',10,1,'','sensor'),(987,'0037','Tegangan','temins_iot/0037/data/tsp','V',100,1,'','power'),(988,'0037','Arus Charging','temins_iot/0037/data/ac','mA',101,1,'','power'),(989,'0037','daya','temins_iot/0037/data/da','watt',101,1,'','power'),(990,'0037','tuc','jenis','1',0,0,'','haloo');
/*!40000 ALTER TABLE `device_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_templates`
--

DROP TABLE IF EXISTS `device_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `template_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_code` (`template_code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_templates`
--

LOCK TABLES `device_templates` WRITE;
/*!40000 ALTER TABLE `device_templates` DISABLE KEYS */;
INSERT INTO `device_templates` VALUES (1,'AWS','AWS '),(2,'Smart_Farm','Smart Farming'),(3,'AWLR','AWLR');
/*!40000 ALTER TABLE `device_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sensor_configs`
--

DROP TABLE IF EXISTS `sensor_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sensor_configs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_type` enum('AWS','Smart_Farm','AWL','Gabungan') NOT NULL,
  `parameter_name` varchar(50) DEFAULT NULL,
  `mqtt_topic` varchar(100) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `display_order` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sensor_configs`
--

LOCK TABLES `sensor_configs` WRITE;
/*!40000 ALTER TABLE `sensor_configs` DISABLE KEYS */;
INSERT INTO `sensor_configs` VALUES (1,'AWS','Suhu Udara','aws/suhu','°C',1),(2,'AWS','Kelembapan','aws/hum','%',2),(3,'AWS','Tekanan Udara','aws/press','hPa',3),(4,'AWS','Kecepatan Angin','aws/wind_spd','m/s',4),(5,'AWS','Arah Angin','aws/wind_dir','°',5),(6,'AWS','Curah Hujan','aws/rain','mm',6),(7,'AWS','Intensitas Cahaya','aws/lux','lx',7),(8,'AWS','UV Index','aws/uv','idx',8),(9,'AWS','PM 2.5','aws/pm25','µg',9),(10,'AWS','Baterai','aws/batt','V',10),(11,'Smart_Farm','Suhu Tanah','farm/soil_temp','°C',1),(12,'Smart_Farm','Kelembapan Tanah','farm/soil_hum','%',2),(13,'Smart_Farm','pH Tanah','farm/ph','pH',3),(14,'Smart_Farm','Nutrisi (NPK)','farm/npk','ppm',4),(15,'Smart_Farm','Suhu Ruang','farm/temp','°C',5);
/*!40000 ALTER TABLE `sensor_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sensor_logs`
--

DROP TABLE IF EXISTS `sensor_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sensor_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_unique_id` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `parameter_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `value` float NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `topic` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device_time` (`device_unique_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sensor_logs`
--

LOCK TABLES `sensor_logs` WRITE;
/*!40000 ALTER TABLE `sensor_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `sensor_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `template_params`
--

DROP TABLE IF EXISTS `template_params`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `template_params` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_id` int NOT NULL,
  `param_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `mqtt_suffix` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `unit` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `data` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'su',
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `template_params_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `device_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=273 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `template_params`
--

LOCK TABLES `template_params` WRITE;
/*!40000 ALTER TABLE `template_params` DISABLE KEYS */;
INSERT INTO `template_params` VALUES (224,1,'Suhu Udara','su','°C','su'),(225,1,'Kelembapan Udara','ku','%','ku'),(226,1,'Kecepatan Angin','ka','m/s','ka'),(227,1,'Arah Angin','aa','°','aa'),(228,1,'Radiasi Matahari','rm','W/m²','rm'),(229,1,'Tekanan Udara','tu','hPa','tu'),(230,1,'Curah Hujan Berjalan','ch','mm','ch'),(231,1,'Baterai','tsp','V','tsp'),(232,1,'Tegangan','tsp','v','tsp'),(233,1,'Arus Charging','ac','mA','ac'),(239,2,'Suhu Tanah','st','°C','st'),(240,2,'Kelembapan Tanah','kt','%','kt'),(241,2,'pH Tanah','pht','pH','pht'),(242,2,'ec','ec','n','ec'),(243,2,'tds','tds','n','tds'),(244,2,'konduktivitas tanah ','kdt','n','kdt'),(245,2,'Nitrogen','n','n','n'),(246,2,'Phosphor','p','n','p'),(247,2,'Kalium','k','n','k'),(248,2,'garam','g','n','g'),(249,2,'Baterai','tsp','V','tsp'),(250,2,'Arus charging','ch','mA','ac'),(251,2,'Daya ','da','w','da'),(268,3,'udara mm','tum','cm','tum'),(269,3,'udara cm','tuc','cm','tuc'),(270,3,'sensor3','tac','cm','tac'),(271,3,'sensor4','tam','cm','tam'),(272,3,'batre','tsp','V','bat');
/*!40000 ALTER TABLE `template_params` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_devices`
--

DROP TABLE IF EXISTS `user_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_devices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `owner_name` varchar(70) NOT NULL,
  `device_type` varchar(50) NOT NULL,
  `device_unique_id` varchar(50) DEFAULT NULL,
  `city` varchar(50) NOT NULL,
  `location` varchar(50) NOT NULL,
  `internet_no` varchar(50) NOT NULL,
  `pic_contact` varchar(50) NOT NULL,
  `pic` varchar(11) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT NULL,
  `status` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_devices`
--

LOCK TABLES `user_devices` WRITE;
/*!40000 ALTER TABLE `user_devices` DISABLE KEYS */;
INSERT INTO `user_devices` VALUES (23,25,'aws','temins','AWS','0015','semarang','kantor','-','-','-','WIB',1),(24,27,'aws','BRIN','AWS','0021','Cibinong','KST Soekarno','089528361698','-','-','WIB',1),(25,28,'aws','BRIN','AWS','0023','Bogor','KST Kebun Raya Bogor','089528361560','-','-','WIB',1),(26,29,'aws','BRIN','AWS','0026','Pontianak','KKI Kebun Raya Pontianak','0895326192687','-','-','WITA',1),(27,30,'aws','BRIN 12 nov','AWS','0024','Jogjakarta','BRIN Jogja','085167067005','081329295975','Bu Winarni','WIB',1),(29,33,'44','44','AWlR','4444','4','4','4','4','4',NULL,1),(30,34,'AWS','Pertamina','AWS','0031','jakarta','jakarta','085','145','DITA',NULL,1),(32,36,'AWLR','PT Vale Indonesia Via Supra','AWlR','0014','pomalaa','Site Pomala Sulawesi','081xxx','082117508224','Rifky',NULL,1),(33,37,'AWLR','PT Vale Indonesia Via Supra','AWlR','0013','pomalaa','Site Pomala Sulawesi','081xxx','082117508224','Rifky',NULL,1),(34,38,'AWS','Dita','AWS','0019','Semarang','Kota lama','085','145','Dita',NULL,1),(35,39,'AWS','BRIN','AWS','0025','Garut','Garut','08','-','-',NULL,1),(36,40,'AWS','BRIN','AWS','0027','Agam','KKI Stasiun Observasi','085119722554','2131','ok','WIB',1),(38,42,'AWS','BRIN','AWS','0028','Bali ','BRIN Bali','0895326192683','-','-','WITA',1),(39,43,'AWS','BRIN','AWS','0029','Lombok','Lombok','089532619285','-','-','WITA',1),(40,44,'AWS','BRIN','AWS','0030','Lampung','Lampung','0895428856807','-','-',NULL,1),(41,45,'AWS','BRIN','AWS','0031','Surabaya','Surabaya','0895428856807','-','-',NULL,1),(42,46,'AWS','BRIN','AWS','0032','Purwodadi','Purwodadi','0895326192690','-','-','WIB',1),(43,47,'AWS','BRIN','AWS','0033','Biak','Biak','0895428856792','-','-','WIB',1),(44,48,'AWS','BRIN','AWS','0034','ambon','ambon','0895322026635','-','-',NULL,1),(45,49,'AWLR','Pontianak','AWlR','0040','Pontianak','Pontianak','089','0','-','WITA',1),(46,50,'AWS','BRIN','AWS','0035','Kupang','Kupang','0','0','Abdul',NULL,1),(47,51,'AWS','Temins','AWS','0007','Semarang','kantor','-','-','-',NULL,1),(48,52,'SF','Temins','Smart_Farm','0050','semarang','kantor','-','-','-',NULL,1),(49,53,'AWS','Temins','AWS','0100','Tembalang','Semarang','08','-','-',NULL,1),(55,59,'as','2','Smart_Farm','4452','2','2','2','12','2','WIB',0),(60,65,'awlr','BRIN','AWLR','3345','Cibinong','KST Soekarno','-','alat1','-',NULL,1),(64,69,'aws','BRIN','AWS','0022','Serpong','KST BJ Habibie','0895326192691','-','-','WITA',1),(89,94,'awlr','awlr','AWLR','0098','Jogjakarta','KKI Kebun Raya Pontianak','0895326192687','tes','5555','WITA',1),(90,95,'awlr','-','AWLR','0012','-','semarang','-','-','-','WITA',1),(91,96,'aws','PT Vale indonesia Via Supra','AWS','0011','Polama','site Polama Sulawesi ','081190046225','082117508224','Rifky','WIB',1),(93,98,'AWLR','PT Sumber Masanda Jaya','AWLR','0038','Brebes','-','0895321907592','-','-','WIB',1),(95,102,'Aws','Tes','AWS','021','Sm','Smg','0054','93838','Yaa','WIB',1),(96,103,'AWS','','AWS','0037','','','','','','WITA',1);
/*!40000 ALTER TABLE `user_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_sensor_charts`
--

DROP TABLE IF EXISTS `user_sensor_charts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sensor_charts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_unique_id` varchar(50) NOT NULL,
  `device_setting_id` int NOT NULL,
  `chart_order` int DEFAULT '0',
  `is_active` tinyint DEFAULT '1',
  `data` varchar(50) NOT NULL DEFAULT 'su',
  PRIMARY KEY (`id`),
  KEY `device_setting_id` (`device_setting_id`),
  CONSTRAINT `user_sensor_charts_ibfk_1` FOREIGN KEY (`device_setting_id`) REFERENCES `device_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2097 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_sensor_charts`
--

LOCK TABLES `user_sensor_charts` WRITE;
/*!40000 ALTER TABLE `user_sensor_charts` DISABLE KEYS */;
INSERT INTO `user_sensor_charts` VALUES (110,45,'0031',283,10,1,'su'),(111,45,'0031',386,10,1,'ku'),(112,45,'0031',387,10,1,'ka'),(113,45,'0031',388,10,1,'aa'),(114,45,'0031',286,10,1,''),(115,45,'0031',389,10,1,'rm'),(116,45,'0031',390,10,1,'tu'),(117,45,'0031',391,10,1,'ch'),(118,45,'0031',290,10,1,'chj'),(119,45,'0031',291,10,1,'chk'),(120,45,'0031',292,10,1,'tsp'),(129,39,'0025',313,10,1,'su'),(130,39,'0025',314,10,1,'ku'),(131,39,'0025',315,10,1,'ka'),(132,39,'0025',316,10,1,'aa'),(133,39,'0025',317,10,1,'rm'),(134,39,'0025',318,10,1,'hpa'),(135,39,'0025',319,10,1,'ch'),(136,39,'0025',322,10,1,'tsp'),(147,53,'0100',464,10,1,'su'),(148,53,'0100',465,10,1,'ku'),(149,53,'0100',466,10,1,'ka'),(150,53,'0100',467,10,1,'aa'),(151,53,'0100',468,10,1,'rm'),(152,53,'0100',469,10,1,'tu'),(153,53,'0100',470,10,1,'ch'),(154,53,'0100',473,10,1,'tsp'),(187,52,'0050',459,10,1,'soil_t'),(188,52,'0050',460,10,1,'soil_h'),(189,52,'0050',461,10,1,'ph'),(198,51,'0007',447,10,1,'su'),(199,51,'0007',448,10,1,'ku'),(200,51,'0007',449,10,1,'ka'),(201,51,'0007',450,10,1,'aa'),(202,51,'0007',451,10,1,'rm'),(203,51,'0007',452,10,1,'tu'),(204,51,'0007',453,10,1,'ch'),(205,51,'0007',456,10,1,'tsp'),(206,50,'0035',435,10,1,'su'),(207,50,'0035',436,10,1,'ku'),(208,50,'0035',437,10,1,'ka'),(209,50,'0035',438,10,1,'aa'),(210,50,'0035',439,10,1,'rm'),(211,50,'0035',440,10,1,'tu'),(212,50,'0035',441,10,1,'ch'),(213,50,'0035',444,10,1,'tsp'),(214,48,'0034',421,10,1,'su'),(215,48,'0034',422,10,1,'ku'),(216,48,'0034',423,10,1,'ka'),(217,48,'0034',424,10,1,'aa'),(218,48,'0034',425,10,1,'rm'),(219,48,'0034',426,10,1,'tu'),(220,48,'0034',427,10,1,'ch'),(221,48,'0034',430,10,1,'tsp'),(238,44,'0030',373,10,1,'su'),(239,44,'0030',374,10,1,'ku'),(240,44,'0030',375,10,1,'ka'),(241,44,'0030',376,10,1,'aa'),(242,44,'0030',377,10,1,'rm'),(243,44,'0030',378,10,1,'tu'),(244,44,'0030',379,10,1,'ch'),(245,44,'0030',382,10,1,'tsp'),(246,43,'0029',361,10,1,'su'),(247,43,'0029',362,10,1,'ku'),(248,43,'0029',363,10,1,'ka'),(249,43,'0029',364,10,1,'aa'),(250,43,'0029',365,10,1,'rm'),(251,43,'0029',366,10,1,'tu'),(252,43,'0029',367,10,1,'ch'),(253,43,'0029',370,10,1,'tsp'),(764,38,'0019',301,10,1,'su'),(765,38,'0019',302,10,1,'ku'),(766,38,'0019',303,10,1,'ka'),(767,38,'0019',304,10,1,'aa'),(768,38,'0019',305,10,1,'rm'),(769,38,'0019',306,10,1,'tu'),(770,38,'0019',307,10,1,'ch'),(771,38,'0019',310,10,1,'tsp'),(982,65,'3345',574,2,1,'tuc'),(983,65,'3345',777,10,1,'result_tinggi_air'),(1121,46,'0032',397,10,1,'su'),(1122,46,'0032',398,10,1,'ku'),(1123,46,'0032',399,10,1,'ka'),(1124,46,'0032',400,10,1,'aa'),(1125,46,'0032',401,10,1,'rm'),(1126,46,'0032',402,10,1,'tu'),(1127,46,'0032',403,10,1,'ch'),(1128,46,'0032',406,10,1,'tsp'),(1129,25,'0015',216,10,1,'su'),(1130,25,'0015',217,10,1,'ku'),(1131,25,'0015',218,10,1,'ka'),(1132,25,'0015',219,10,1,'aa'),(1133,25,'0015',220,10,1,'rm'),(1134,25,'0015',221,10,1,'tu'),(1135,25,'0015',222,10,1,'ch'),(1136,25,'0015',225,10,1,'tsp'),(1326,27,'0021',228,9,1,'su'),(1327,27,'0021',229,1,1,'ku'),(1328,27,'0021',230,10,1,'ka'),(1329,27,'0021',231,10,1,'aa'),(1330,27,'0021',232,10,1,'rm'),(1331,27,'0021',233,10,1,'tu'),(1332,27,'0021',234,10,1,'ch'),(1333,27,'0021',237,10,1,'tsp'),(1344,28,'0023',240,10,1,'su'),(1345,28,'0023',241,10,1,'lu'),(1346,28,'0023',242,10,1,'ka'),(1347,28,'0023',243,10,1,'aa'),(1348,28,'0023',244,10,1,'rm'),(1349,28,'0023',245,10,1,'tu'),(1350,28,'0023',246,10,1,'ch'),(1351,28,'0023',249,10,1,'tsp'),(1478,29,'0026',252,10,1,'su'),(1479,29,'0026',253,10,1,'ku'),(1480,29,'0026',254,10,1,'ka'),(1481,29,'0026',255,10,1,'aa'),(1482,29,'0026',256,10,1,'rm'),(1483,29,'0026',257,10,1,'tu'),(1484,29,'0026',258,10,1,'ch'),(1485,29,'0026',261,10,1,'tsp'),(1555,96,'0011',915,1,1,'su'),(1556,96,'0011',916,2,1,'ku'),(1557,96,'0011',917,3,1,'ka'),(1558,96,'0011',918,4,1,'aa'),(1559,96,'0011',919,5,1,'rm'),(1560,96,'0011',920,6,1,'tu'),(1561,96,'0011',921,7,1,'ch'),(1562,96,'0011',922,8,1,'tsp'),(1563,96,'0011',923,9,1,'tsp'),(1564,96,'0011',924,10,1,'ac'),(1711,59,'4452',531,10,1,'st'),(1712,59,'4452',532,10,1,'kt'),(1713,59,'4452',533,10,1,'pht'),(1714,59,'4452',534,10,1,'ec'),(1715,59,'4452',535,10,1,'tds'),(1716,59,'4452',536,10,1,'kdt'),(1717,59,'4452',537,10,1,'n'),(1718,59,'4452',538,10,1,'p'),(1719,59,'4452',539,10,1,'k'),(1720,59,'4452',541,10,1,'tsp'),(1726,69,'0022',620,1,1,'su'),(1727,69,'0022',621,2,1,'ku'),(1728,69,'0022',622,3,1,'ka'),(1729,69,'0022',623,4,1,'aa'),(1730,69,'0022',624,5,1,'rm'),(1731,69,'0022',626,7,1,'ch'),(1732,69,'0022',627,8,1,'tsp'),(1733,69,'0022',629,10,1,'ac'),(1734,30,'0024',264,10,1,'su'),(1735,30,'0024',265,10,1,'ku'),(1736,30,'0024',266,10,1,'ka'),(1737,30,'0024',267,10,1,'aa'),(1738,30,'0024',268,10,1,'rm'),(1739,30,'0024',269,10,1,'tu'),(1740,30,'0024',270,10,1,'ch'),(1741,30,'0024',273,10,1,'tsp'),(1888,95,'0012',909,10,1,'tsp'),(1889,95,'0012',914,111,1,'ta'),(1898,42,'0028',349,10,1,'su2'),(1899,42,'0028',350,10,1,'ku2'),(1900,42,'0028',351,10,1,'ka'),(1901,42,'0028',352,10,1,'aa'),(1902,42,'0028',353,10,1,'rm'),(1903,42,'0028',354,10,1,'tu'),(1904,42,'0028',358,10,1,'tsp'),(1905,42,'0028',960,10,1,'ch'),(1990,98,'0038',940,111,1,'tuc'),(1991,98,'0038',941,111,1,'result_tinggi_air'),(1992,98,'0038',943,5,1,'tsp'),(2036,47,'0033',409,10,1,'su'),(2037,47,'0033',410,10,1,'ku'),(2038,47,'0033',411,10,1,'ka'),(2039,47,'0033',412,10,1,'aa'),(2040,47,'0033',413,10,1,'rm'),(2041,47,'0033',414,10,1,'tu'),(2042,47,'0033',415,10,1,'ch'),(2043,47,'0033',418,10,1,'tsp'),(2054,94,'0098',896,1,1,'tum'),(2055,94,'0098',897,2,1,'tuc'),(2056,94,'0098',898,3,1,'tac'),(2057,94,'0098',899,4,1,'tam'),(2058,94,'0098',900,5,1,'bat'),(2059,40,'0027',325,10,1,'su'),(2060,40,'0027',326,10,1,'ku'),(2061,40,'0027',327,10,1,'ka'),(2062,40,'0027',328,10,1,'aa'),(2063,40,'0027',329,10,1,'rm'),(2064,40,'0027',330,10,1,'tu'),(2065,40,'0027',331,10,1,'ch'),(2066,40,'0027',334,10,1,'tsp'),(2087,103,'0037',977,1,1,'su'),(2088,103,'0037',978,2,1,'ku'),(2089,103,'0037',979,3,1,'ka'),(2090,103,'0037',980,4,1,'aa'),(2091,103,'0037',981,5,1,'rm'),(2092,103,'0037',982,6,1,'tu'),(2093,103,'0037',983,7,1,'ch'),(2094,103,'0037',984,8,1,'tsp'),(2095,103,'0037',985,9,1,'tsp'),(2096,103,'0037',986,10,1,'ac');
/*!40000 ALTER TABLE `user_sensor_charts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL,
  `diBuat` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=104 DEFAULT CHARSET=utf8mb3;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$gHZ6v6NdXKkUQOGO5SW4KONJp1AI9BakiqBx9nFBgL6bVbj2Iu2Cy','admin','2025-12-12 00:00:00'),(25,'temins','$2y$10$82qRGSqxiYSc6TJGPq6koe9gcKX37nm6I8II6u8efpYNanG9PC98i','user','2025-12-12 00:00:00'),(27,'BRINCibinong','$2y$10$LMAVeS0/pedz7IWqy.U7tec4MO/Bgbq0vNBu8X9Ps58MZGEjgChbq','user','2025-12-12 00:00:00'),(28,'BRINKebunRayaBogor','$2y$10$atdVBgMnNGMHdEm0gjf.KuB/b6qe0yxiAM4PiN.7X9Hpmkf8HIsFi','user','2025-12-12 00:00:00'),(29,'BRINPontianak','$2y$10$MLx6lC18Of.DqsZv4tn7.unKQIBnCyQIQ.I8TKnj6ccMXPXXkkAdi','user','2025-12-12 00:00:00'),(30,'BRINJogja','$2y$10$4Tskk.w32oLWhb6dJIKEx.i8NR.zzRvmJcPBvvROEnRMMUJjHxCMq','user','2025-12-12 00:00:00'),(31,'awlr','$2y$10$rgWdD05CPZj4OkaTmb.fpOJt/qgU6dtqmUYxb5oVzsplV3cswOzxm','user','2025-12-12 00:00:00'),(32,'11','$2y$10$Ljqfp2.XBPOIkZfwAqRkGul9VRrMubHqwibrN2ki5PEel8yUowdsG','user','2025-12-12 00:00:00'),(33,'4','$2y$10$fKK0Pv5jnne4r47K75ZnVuszHTygY2BnF03MprfZrKiVspg/My5xO','user','2025-12-12 00:00:00'),(34,'pertamina','$2y$10$Jk1BNuICiaam.wUK9b11yObauLOp73SWeVhZs6XLvR9fdsK1o8xBi','user','2025-12-12 00:00:00'),(36,'vale4','$2y$10$EsDj8.vRbCIdTjW1t6z/D.iO34qtzhwCivCB1Uvo6C0.bV52jyONG','user','2025-12-12 00:00:00'),(37,'vale3','$2y$10$kSWesTUjJqsNtedp92sVrudsMXDlVWnby5Se11Spj6x9gIwJcsrvC','user','2025-12-12 00:00:00'),(38,'Dita','$2y$10$D5P2PkL5ZlTWJpZxVmExi.NrI12r337eZBviTHXTMWbiW8foVsg12','user','2025-12-12 00:00:00'),(39,'BRINGarut','$2y$10$7N8HfYy988j/LxT/oNQwGev/WpPikG/1L7zEoC8cafsRXZ4FJRFbq','user','2025-12-12 00:00:00'),(40,'BRINAgam','$2y$10$c2yoSRtBJsVUTy6sDlkkL.HtEyxSpBUfZDfEEh3DAZrQKAS5HK1hC','user','2025-12-12 00:00:00'),(42,'BRINBali','$2y$10$6.vUY2yKO4WNIEV8E9bzD.lUO15RTm0wlvJzFmMBpVoLl79VI3YSa','user','2025-12-12 00:00:00'),(43,'BRINLombok','$2y$10$xM49qQhw7D.TV8Cr/w6ME.o3BKExZiZ1pj2Ihk.x7fCfm20gmGBdC','user','2025-12-12 00:00:00'),(44,'BRINLampung','$2y$10$JCQnQ8/RpFO1WezvqCQOy.waBv39mthbEQvcPBZ6bGmEkbaylMaz6','user','2025-12-12 00:00:00'),(45,'BRINSurabaya','$2y$10$ucYih3qCwDwqk/SvEi7iperY0SmU3Ik3jmmfsnGDByVA1a7iXD9Ry','user','2025-12-12 00:00:00'),(46,'BRINPurwodadi','$2y$10$Q/.IKS1McReQrPcn6TO69.ffY4/3ABdX8mk78C0mQQwzVhFHXDLli','user','2025-12-12 00:00:00'),(47,'BRINBiak','$2y$10$bLAnBtmBP.x3qiib9zIeROCjk.LLxPDTI9TF13jxWtisyc1pvXwSu','user','2025-12-12 00:00:00'),(48,'BRINAmbon','$2y$10$GZ39KcWMPxgY.Tq3qBxmLuBPXpfr1dj1byVHX/05g3Hai6wKn2Pri','user','2025-12-12 00:00:00'),(49,'UNTAN','$2y$10$N.GZvhFEVR5aHy/gjVtRYurmqzbNFBSud/lc.fcruK8WLGYfIq86G','user','2025-12-12 00:00:00'),(50,'BRINKupang','$2y$10$xCT7lzJzU6D/1ukdUjxN.OMnxfSuDLOcOz.7pt8YkEVVeCm/0D1We','user','2025-12-12 00:00:00'),(51,'TeminsRiset','$2y$10$Rjoh.CK6oTVgNHfVoZIq2eIIinIEi6fvB56QUepuQZFs4Co8SirQy','user','2025-12-12 00:00:00'),(52,'SFBandung','$2y$10$k.RJvx/EfyRABYUMaXXmFejoUOPDegL1aBDJAzKcdlF22RCkCww0G','user','2025-12-12 00:00:00'),(53,'demo','$2y$10$q770hSYxRmwZriPDs3xJyO40b18SR5JNnc8ogF/SqR0wzku5j6Z.i','user','2025-12-12 00:00:00'),(59,'sf','$2y$10$xXMX23ALlK4wsj8hn1H/8.DVmahoZYMZ2ZOQpIvKPtmbuheJuEkES','user','2025-12-14 00:00:00'),(65,'aw','$2y$10$ICkcuNYU74sT1nYsfdPNfO9Ub3N0gPCSot3jZDzF0SgKg8Y9d2J/.','user','2025-12-15 00:00:00'),(69,'BRINSerpong','$2y$10$0BLBjdGMxQdBNNOtQT7ye.1DMyzsB0OZlVAaKLuPwJLEE9oedg2v.','user','2025-12-17 00:00:00'),(94,'awlrtes','$2y$10$oANnU6NRCPpjx30za/IOdeXjZ6I3vsjYhD8lVyI1n54dd.o/yyQD.','user','2025-12-26 00:00:00'),(95,'awlr1','$2y$10$MztU0NFjSBI.V7NtQ3MWsOfj3Njl5w4WOB8GiuTOEEBixxDNnZnt.','user','2025-12-29 00:00:00'),(96,'vale1','$2y$10$gfN4iSWIwUMIH5uAxcIIdeuqtoY168MA5J8PfyYqvbhnKeX.p7ZSC','user','2025-12-31 00:00:00'),(98,'PTMSJ','$2y$10$hXjUbZMwTfSZ3riAN30uvuAeLIwC5L.vUUogp.8GR3qaumAIIF0z6','user','2026-01-09 18:45:46'),(102,'Tes','$2y$10$LeS6xd4FD5a5/SYm4u8e0uqEfg0M2SbyqVtI2dbbcdns3X6Dqwyhi','user','2026-01-22 17:59:30'),(103,'aws0037','$2y$10$gda3fJpnbfBxm5EzWSZoz.WSfXTSb5LHwEa68LDM3oecq/Wf9xV9e','user','2026-01-29 11:33:03');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-29 13:07:11
