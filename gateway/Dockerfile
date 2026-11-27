FROM nginx:1.30.6-alpine
COPY nginx.conf /etc/nginx/nginx.conf
COPY default.conf /etc/nginx/conf.d/default.conf
COPY auth-proxy.conf /etc/nginx/auth-proxy.conf
COPY clients-proxy.conf /etc/nginx/clients-proxy.conf
COPY appointments-proxy.conf /etc/nginx/appointments-proxy.conf
USER nginx
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s CMD wget -q -O /dev/null http://127.0.0.1:8080/gateway-health || exit 1
ENTRYPOINT ["nginx", "-g", "daemon off;"]
