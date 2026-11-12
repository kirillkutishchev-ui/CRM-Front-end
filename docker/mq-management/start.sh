#!/bin/sh
set -eu
test -s /etc/mq-tls/cert.pem
test -s /etc/mq-tls/key.pem
test -n "${MQ_MANAGEMENT_ALLOWED_IPS:-}"
{
    printf '%s\n' 'server {' 'listen 443 ssl;' 'server_name _;' \
        'ssl_certificate /etc/mq-tls/cert.pem;' 'ssl_certificate_key /etc/mq-tls/key.pem;' \
        'ssl_protocols TLSv1.2 TLSv1.3;'
    for cidr in $(printf '%s' "$MQ_MANAGEMENT_ALLOWED_IPS" | tr ',' ' '); do
        case "$cidr" in *[!0-9a-fA-F.:/]*|'') echo 'Invalid allowed IP/CIDR' >&2; exit 1;; esac
        printf 'allow %s;\n' "$cidr"
    done
    printf '%s\n' 'deny all;' 'location / {' 'proxy_pass http://crm_rabbitmq:15672;' \
        'proxy_set_header Host $host;' 'proxy_set_header X-Real-IP $remote_addr;' \
        'proxy_set_header X-Forwarded-Proto https;' 'proxy_read_timeout 60s;' '}' '}'
} > /etc/nginx/conf.d/default.conf
nginx -t
exec nginx -g 'daemon off;'
