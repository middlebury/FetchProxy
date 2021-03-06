-- phpMyAdmin SQL Dump
-- version 3.4.3.1
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Aug 17, 2012 at 01:48 PM
-- Server version: 5.0.95
-- PHP Version: 5.3.10

--
-- Database: `afranco_fetchproxy`
--

-- --------------------------------------------------------

--
-- Table structure for table `feeds`
--

CREATE TABLE IF NOT EXISTS `feeds` (
  `id` varchar(32) NOT NULL,
  `url` text NOT NULL,
  `headers` text,
  `data` longblob,
  `status_code` int(11) NOT NULL default '200',
  `status_msg` varchar(50) NOT NULL default 'OK',
  `first_fetch` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `last_fetch` datetime NOT NULL,
  `last_access` datetime NOT NULL,
  `num_access` int(11) NOT NULL default '1',
  `num_errors` int(11) NOT NULL default '0',
  `custom_ttl` int(11) default NULL,
  PRIMARY KEY  (`id`),
  KEY `last_access` (`last_access`),
  KEY `fetch_index` (`custom_ttl`,`last_fetch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `log`
--

CREATE TABLE IF NOT EXISTS `log` (
  `id` int(11) NOT NULL auto_increment,
  `tstamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `event_type` varchar(20) default NULL,
  `feed_id` varchar(32) default NULL,
  `feed_url` text,
  `fetch_url` text,
  `message` text NOT NULL,
  `num_errors` int(11) default NULL,
  PRIMARY KEY  (`id`),
  KEY `event_type` (`event_type`),
  KEY `feed_id` (`feed_id`),
  KEY `tstamp` (`tstamp`,`feed_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `processes`
--

CREATE TABLE IF NOT EXISTS `processes` (
  `pid` int(11) NOT NULL,
  `tstamp` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
