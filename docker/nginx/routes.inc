    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    # CSP adalah milik kedua app Next (deliverable keamanan batch frontend) dan tidak diduplikasi di sini.
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

    location / {
        limit_req zone=web burst=100 nodelay;
        limit_req zone=admin burst=30 nodelay;
        proxy_pass http://$frontend_upstream;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $edge_scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 60s;
    }
