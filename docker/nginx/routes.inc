    include /etc/nginx/elzara/security.inc;
    client_max_body_size 1m;

    location = /nginx-healthz { access_log off; return 200 "ok\n"; }

    # Webhook provider: dipisah dari /api/ supaya buffer, timeout, dan zone limitnya sendiri.
    location /api/v1/webhooks/ {
        limit_req zone=webhooks burst=100 nodelay;
        fastcgi_pass api_fpm;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param DOCUMENT_ROOT /app/public;
        fastcgi_param HTTPS $edge_https if_not_empty;
        # Body mentah + tanda tangan provider tidak boleh dipotong buffer atau retry.
        fastcgi_request_buffering off;
        fastcgi_buffering off;
        fastcgi_read_timeout 60s;
        client_max_body_size 4m;
        access_log /var/log/nginx/webhooks.log;
    }

    location /api/v1/internal/ { return 404; }
    location = /api/v1/internal { return 404; }

    location /api/ {
        limit_req zone=api burst=50 nodelay;
        fastcgi_pass api_fpm;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param DOCUMENT_ROOT /app/public;
        fastcgi_param HTTPS $edge_https if_not_empty;
        fastcgi_read_timeout 30s;
    }

    location = /sanctum/csrf-cookie {
        limit_req zone=api burst=50 nodelay;
        include fastcgi_params;
        fastcgi_pass api_fpm;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param DOCUMENT_ROOT /app/public;
        fastcgi_param HTTPS $edge_https if_not_empty;
    }

    # Build assets and icons must not consume the per-page rate limit.
    # No add_header here: inherit the security headers from the server.
    location ~ ^/(?:_nuxt/|_next/static/|favicon\.(?:ico|png|svg)$|brand/elzara-mark\.png$) {
        proxy_pass http://$frontend_upstream;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $edge_scheme;
        proxy_read_timeout 60s;
    }

    location / {
        limit_req zone=web burst=100 nodelay;
        limit_req zone=admin burst=30 nodelay;
        proxy_pass http://$frontend_upstream;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $edge_scheme;
        # Shopper IP for storefront SSR API calls (apps/web/lib/shopper-identity.ts); overwritten, never client-supplied.
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 60s;
    }
