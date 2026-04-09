# shh


![api demo](docs/hero.gif)
share a secret once. the server never sees the plaintext. first open burns it.

the use case is narrow: you need to hand someone a password or api key, and you don't want it sitting in slack or email archives forever. shh gives you a link that works exactly once, with the decrypt key living in the url fragment so it never reaches my server.

## how it works

- your browser generates a random aes-gcm-256 key and a 12-byte iv.
- it encrypts the secret, posts `{ciphertext, iv}` to `/api/new`. the server stores those bytes under a random token. the key never leaves the browser.
- you get a url like `https://shh.frkhd.com/x/<token>#k=<base64url-key>`. the bit after `#` is the fragment — browsers don't send it in http requests, so my server can't log it even if i wanted to.
- recipient opens the link. their browser posts to `/api/burn/<token>`, the server atomically reads the row, deletes it, and returns the ciphertext. the browser decrypts using the fragment. refresh the page and you get a 404. the row is gone.

## install

```
git clone https://github.com/f4rkh4d/shh
cd shh
composer install
cp .env.example .env
# edit SHH_ADMIN_TOKEN to something not "changeme"
php -S 127.0.0.1:8088 -t public router.php
```

open http://127.0.0.1:8088 and paste a secret.

## self-host

behind nginx + php-fpm, something like:

```
server {
    listen 443 ssl http2;
    server_name shh.example.com;
    root /srv/shh/public;
    index index.html;

    location /api/new    { try_files $uri /router.php; }
    location ~ ^/api/burn/[a-z2-9]+$  { try_files $uri /router.php; }
    location /api/gc     { try_files $uri /router.php; }
    location ~ ^/x/[a-z2-9]+$ { try_files $uri /router.php; }

    location = /router.php {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /srv/shh/router.php;
    }
}
```

sweep expired rows with a cron hitting `/api/gc` and passing `X-Admin-Token: $SHH_ADMIN_TOKEN`.

the hosted instance is at [shh.frkhd.com](https://shh.frkhd.com) if you just want to use it without running anything.

## security model

**what the server actually sees:** ciphertext bytes, a 12-byte iv, a ttl, the ip that submitted (only held in the rate-limit file for 60 seconds). that's it. it never sees plaintext, it never sees the aes key.

**what an attacker with full db access gets:** a pile of aes-gcm ciphertexts with no keys. aes-gcm 256 with random ivs and no key material is not brute-forceable with anything we know about.

**what you're still trusting:** https so the ciphertext+key don't both get sniffed in transit, the integrity of the browser running the js (no malicious extensions), and whatever you do with the plaintext after decrypting (don't paste it into a chat that archives forever).

**caveats, being honest:** if your laptop is owned, shh doesn't help. if you paste the secret into a site that exfiltrates to a logging service, shh doesn't help. this is the layer above: stopping secrets from living forever in slack or email. it's not an endpoint security tool.

probably has bugs i haven't hit yet. rate limiting is file-backed and kind of naive — good enough for a hobby instance, i wouldn't put it in front of anything serious without a real limiter.

## license

mit, see LICENSE.
