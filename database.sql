-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: hotelmanagementsystem
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `password` char(255) NOT NULL,
  `address` varchar(50) NOT NULL,
  `phone_no` varchar(15) NOT NULL,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `email_uniq` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_name` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `password` char(255) NOT NULL,
  `address` varchar(10) NOT NULL,
  `phone_no` varchar(15) NOT NULL,
  `job` varchar(15) NOT NULL,
  `salary` decimal(6,2) NOT NULL,
  `sales` int(11) NOT NULL,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `email_unq` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `quantity` int(6) NOT NULL,
  `order_type` varchar(10) NOT NULL DEFAULT '"Dine-in"',
  `customer_id` int(11) NOT NULL,
  PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_ref` varchar(50) NOT NULL,
  `order_id` int(11) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `amount` decimal(6,2) NOT NULL,
  `payment_status` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `payment_ref_unique` (`payment_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(30) NOT NULL,
  `price` decimal(6,2) NOT NULL,
  `description` varchar(100) NOT NULL,
  `product_path` varchar(100) DEFAULT NULL,
  `datetimeadded` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `product_name_uniq` (`product_name`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (9,'omlette',350.00,'Best omlette at an affordable price less spicey','1778251745_omelettes.jpeg','2026-05-08 17:49:05'),(10,'scrumble',278.00,'Have your eggs cooked to your liking','1778252037_scrambled.jpeg','2026-05-08 17:53:57'),(11,'Poached',297.00,'Like poached eggs we got you covered here','1778252145_poached.jpeg','2026-05-08 17:55:45'),(12,' Beef Stew',700.00,'Spicey Beef Stew. Have it with anything you want.','1778257862_beefstew.jpeg','2026-05-08 19:31:02'),(15,'Samosa',80.00,'Love samosa dont worry we got you covered','1778259132_images.jpeg','2026-05-08 19:52:12'),(17,'Kebabs',300.00,'Want a snack dont worry we got you covered','1778261341_kebabs.jpeg','2026-05-08 20:29:01'),(18,'Chips',250.00,'Deep fried potato chips','1778261445_chips.jpeg','2026-05-08 20:30:45'),(19,'Crips',200.00,'Deep fried, sliced cassava crips','1778261547_crips.jpeg','2026-05-08 20:32:27'),(21,'Chicken',1200.00,'Fried chicken','1778261734_download (2).jpeg','2026-05-08 20:35:34'),(22,'Smoki',100.00,'Deep fried','1778261938_sausage.jpeg','2026-05-08 20:38:58'),(23,'Matoke',800.00,'Traditonaly made','1778262145_download (1).jpeg','2026-05-08 20:42:25'),(24,'Pizza',1400.00,'Strawbery Pizza','1778262197_download.jpeg','2026-05-08 20:43:17'),(25,'Black Coffe',200.00,'Cappuccino Coffee','1778262353_black coffee.jpeg','2026-05-08 20:45:53'),(26,'Githeri',300.00,'Made with traditional spices','1778262538_githeri.jpeg','2026-05-08 20:48:58'),(27,'Biscuit',80.00,'Brown Biscuits','1778310569_biscuit.jpeg','2026-05-09 10:09:29'),(28,'milk',100.00,'Fresh Milk from Dairy cow','1778940147_images.jpg','2026-05-16 17:02:27'),(29,'chips-kuku',900.00,'Fried Chips and Chicken popsticks','1779463099_download (1).jpg','2026-05-22 18:18:19');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-27 10:42:54
