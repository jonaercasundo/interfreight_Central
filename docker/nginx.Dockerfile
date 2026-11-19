FROM node:22-alpine AS frontend
WORKDIR /src
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:1.27-alpine
COPY public /var/www/html/public
COPY --from=frontend /src/public/build /var/www/html/public/build
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80 443
