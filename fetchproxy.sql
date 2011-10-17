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
  `data` blob,
  `last_fetch` datetime NOT NULL,
  `last_access` datetime NOT NULL,
  `num_access` int(11) NOT NULL default '0',
  `num_errors` int(11) NOT NULL default '0',
  `custom_ttl` int(11) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `log`
--

CREATE TABLE IF NOT EXISTS `log` (
  `id` int(11) NOT NULL auto_increment,
  `tstamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
  `feed_id` varchar(32) NOT NULL,
  `feed_url` text NOT NULL,
  `fetch_url` text NOT NULL,
  `message` text NOT NULL,
  `num_errors` int(11) NOT NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
