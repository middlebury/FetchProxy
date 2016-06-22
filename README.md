FetchProxy
==========

FetchProxy is a small web service that transparently subscribes to RSS, iCal, and
other data feeds so that clients don't have to wait on slow data sources.

License
-------
Author: Adam Franco  
URI: http://github.com/middlebury/FetchProxy  
Copyright (c) 2011, The President and Fellows of Middlebury College.

Unless noted otherwise you are granted license to use redistribute,
and/or modify FetchProxy and its associated components under the terms of
the GNU General Public License (GPL) version 3 or later.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

A copy of the GNU General Public License can be found in the same directory as this
file, with the file name GPL-LICENSE.txt.

Why FetchProxy?
---------------
The particular use-case that FetchProxy was designed for is as a feed caching mechanism
for our large Drupal-based [university website](http://www.middlebury.edu/) and
Kurogo-based [mobile website](http://m.middlebury.edu/). Both of these sites make heavy use of RSS and iCAL feed subscriptions to repurpose content from a variety of campus blogs, calendars, and
other data sources. While  the redisplay of this data has greatly enriched our web presence,
it has come at the cost of performance on the front end sites.
Even though we had many layers of data caching in place, when the caches *did*
need to be refreshed the Drupal or Kurogo page-load would have to wait while the cache was refreshed.
When many pages have 3-5 blog-feeds displayed in side-bars, the second or two
it took for the blog system to generate each feed combined to cause the unlucky viewer
a delay of many seconds for the Drupal or Kurogo caches to be refreshed and their page to load.

To solve the problem of delays due to feed generation and waiting for remote sites
we needed a feed-caching mechanism with the following features:

*   Auto subscribe/cache to any feed requested via HTTP GET

    Any service that requires additional web-service requests or manual interaction
    to set up subscription would require way too many changes on the Drupal and Kurogo ends.
    As well subscription management becomes more complex.

*   Return quickly no matter what.  
    *For the first request ever, a delay while fetching may be acceptable.*

    We don't want Drupal or Kurogo ever to have to sit around waiting for a feed.
    If there was an error fetching the feed via cron, then an error should be returned
    quickly rather than waiting for the source to time out.

*   Always keep the last cached copy.

    This goes hand-in-hand with returning quickly, always keep a copy around so that
    it can be returned rather than forcing Drupal to wait for a fresh version.

*   Update asynchronously.

    Updates should be triggered via cron. Clients (the Drupal and Kurogo sites) should
    get the cached version right away and not wait on a fresh one.

*   Support arbitrary content (RSS and Atom feeds, iCal feeds, KML documents, etc.)

We looked at a number of existing options before deciding to build FetchProxy:

*   Server side RSS Readers/aggregators (such as RSSLounge, GoogleReader, etc)

    Pros:
    *   Can handle periodic fetching of content
    *   Will keep all content stored/cached

    Cons:
    *   No auto-subscribe or requires extra web-service API calls to set up subscription.
    *   Won't support iCal, KML, etc

*   Caching Web Proxies (Squid, Polipo, etc)

    Pros:
    *   Transparent content handling (support any file type)
    *   Can fetch and cache any URL on request ("auto-subscribe")

    Cons:
    *   Don't support periodic fetching of content (We would need to write scripts to
        search through the cache files and re-request the urls periodically.)
    *   May have limited cache lifetimes and might throw away expired content
        before a new version has been fetched.
    *   May not cache error states and force a wait while fetching invalid content.

FetchProxy Features
-------------------
*   **Transparent feed subscription**

    Feeds are always fetched via HTTP GET: `get.php?url=http%3A%2F%2Fwww.example.com%2Ffeed`

    If a feed has not been subscribed yet, it is fetched and auto-subscribed.

*   **Asynchronous feed fetching**

    Feeds are only fetched via cron job after their first request. As long as the feed is subscribed,
    it will be fetched periodically based on a configurable default time-to-live (TTL) or a per-feed
    TTL.

*   **Automatic unsubscription of unused feeds**

    If a feed has not be requested by any client within a configurable interval (default is 30 days)
    then the feed will be deleted and no longer fetched via cron. If it is subsequently requested,
    then it is fetched and subscribed just like any other new feed access.

*   **Fast responses no matter what**

    After the initial subscription fetch, FeedProxy will store the cached data or an error status.
    It will always reply with the cached data or cached error status and never force the client to
    wait for it to fetch new data. We see responses on the order of 5-20ms for most feed access.

*   **Content agnostic**

    FetchProxy stores the full headers and data for the URLs id subscribes to and does not interpret
    or parse the data.

*   **Support for HTTP redirects**

    FetchProxy can follow up to a configurable number of HTTP 301 or 302 redirects.

Installation
------------

1.  Clone the git repository: `git clone git://github.com/middlebury/FetchProxy.git`  
    or download and unzip the code.

2.  Create a database for FetchProxy and run the `fetchproxy.sql` SQL file to create its tables.

3.  Copy `config.php.sample` to `config.php` and edit the values to reflect your database location and preferences.

4.  Add a `cron` job that executes `php /path/to/FetchProxy/cron.php` on a regular basis
    (every 5 minutes is recommended). Run `php cron.php -h` for command-line options related to
    logging and output.

    An example `cron` entry is:

    `*/5 * * * *   /usr/bin/php /var/www/FetchProxy/cron.php --stats --no-log >> /var/log/FetchProxy-cron.log`

5.  Configure the IP address of your client application in the `$allowedClients` array in your `config.php`.
    To prevent users from piping arbitrary content through FetchProxy and potentially serving malware from
    your domain, you must explicitly specify the list of client IPs that are allow to make requests through
    FetchProxy.


Usage
--------

### Fetching Feeds ###

Access feeds by URL-encoding their URL and passing it as the `url` GET parameter to `get.php`.

To fetch a feed that lives at `http://www.example.com/feed` you would make a GET request to:

`http://fetchproxy.example.edu/get.php?url=http%3A%2F%2Fwww.example.com%2Ffeed`

### Custom Refresh cycles ###

Set a non-zero integer number of minutes for a feed in the your database's `feeds.custom_ttl` column. If this column is non-null, the feed will be refreshed on the cron job that occurs that many minutes after the last fetch.
