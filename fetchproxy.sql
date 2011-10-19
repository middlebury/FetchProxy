-- phpMyAdmin SQL Dump
-- version 3.4.3.1
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Oct 17, 2011 at 11:59 AM
-- Server version: 5.0.77
-- PHP Version: 5.3.6

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
  `first_fetch` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_fetch` datetime NOT NULL,
  `last_access` datetime NOT NULL,
  `num_access` int(11) NOT NULL default '0',
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
