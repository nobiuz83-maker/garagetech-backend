FROM nginx:1.30.6-alpine
COPY infrastructure/production/gateway/nginx.conf /etc/nginx/nginx.conf
COPY infrastructure/production/gateway/production.conf.template /etc/nginx/templates/default.conf.template
COPY gateway/auth-proxy.conf /etc/nginx/auth-proxy.conf
COPY gateway/clients-proxy.conf /etc/nginx/clients-proxy.conf
COPY gateway/appointments-proxy.conf /etc/nginx/appointments-proxy.conf
COPY gateway/workflow-headers.conf /etc/nginx/workflow-headers.conf
EXPOSE 80 443
HEALTHCHECK --interval=30s --timeout=3s CMD wget -q -O /dev/null http://127.0.0.1/gateway-health || exit 1
