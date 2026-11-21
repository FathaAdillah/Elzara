add_header Strict-Transport-Security $edge_hsts always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=(self), browsing-topics=()" always;
add_header Content-Security-Policy $edge_csp always;

proxy_hide_header Strict-Transport-Security;
proxy_hide_header X-Content-Type-Options;
proxy_hide_header X-Frame-Options;
proxy_hide_header Referrer-Policy;
proxy_hide_header Permissions-Policy;
fastcgi_hide_header Strict-Transport-Security;
fastcgi_hide_header X-Content-Type-Options;
fastcgi_hide_header X-Frame-Options;
fastcgi_hide_header Referrer-Policy;
fastcgi_hide_header Permissions-Policy;
fastcgi_hide_header Content-Security-Policy;
