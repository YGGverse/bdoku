# DokuWiki Gemini Server

Allows to launch read-only DokuWiki instance using [Gemini Protocol](https://geminiprotocol.net/)

It based on [titan-II](https://github.com/YGGverse/titan-II) server, [gemini-php](https://github.com/YGGverse/gemini-php) to parse DokuWiki data folder, [cache-php](https://github.com/YGGverse/cache-php) to save compiled pages in memory and [manticore](https://github.com/manticoresoftware) for full-text search.

Project under development, please join to work by sending PR or bug report!

## Examples

* `gemini://[301:5eb5:f061:678e::db]` - Mirror of `http://[222:a8e4:50cd:55c:788e:b0a5:4e2f:a92c]`
  * `gemini://how-to-ygg.duckdns.org` - Clearnet alias

## Install

1. `wget https://repo.manticoresearch.com/manticore-repo.noarch.deb`
2. `dpkg -i manticore-repo.noarch.deb`
3. `apt update`
4. `apt install git composer memcached manticore manticore-extra php-fpm php-memcached php-mysql php-mbstring`
5. `git clone https://github.com/YGGverse/dokuwiki-gemini-server.git`
6. `cd dokuwiki-gemini-server`
7. `composer update`

## Setup

1. `cd dokuwiki-gemini-server`
2. `mkdir host/127.0.0.1`
3. `cp example/config.json host/127.0.0.1/config.json`
4. `cd host/127.0.0.1`
5. `openssl req -x509 -newkey rsa:4096 -keyout key.rsa -out cert.pem -days 365 -nodes -subj "/CN=127.0.0.1"`

## Start

Before launch the server, copy or create alias of `path/to/dokuwiki/data` folder to `dokuwiki-gemini-server/host/127.0.0.1` on example above.

On every start, previous memory cache will be cleaned and new search index created.
After `data` folder update, you need just to restart your server with systemd or another process manager.

`php src/server.php 127.0.0.1`

Open `gemini://127.0.0.1` in your favorite [Gemini browser](https://github.com/kr1sp1n/awesome-gemini)!

## Update

1. `cd dokuwiki-gemini-server`
2. `git pull` - get latest codebase from this repository
3. `composer update` - update vendor libraries