# Deploying on the k3s server

The app runs in docker compose on the k3s host. Kubernetes holds only the routing
(Namespace, Service, EndpointSlice, Ingress) so the cluster's Traefik and cert-manager
serve it, and the database is the cluster's shared Postgres.

```
browser -> Cloudflare -> k3s Traefik -> Service -> EndpointSlice -> 10.42.0.1:8090
                                                                     |
                                              docker compose: app, queue, scheduler, redis
                                                                     |
                                              shared Postgres (data namespace, ClusterIP)
```

## First install

1. Clone into `~/apps/social-affiliate` and create `.env` from `.env.example` with at least:

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=                      # php artisan key:generate --show (or docker run ... )
   APP_URL=https://affiliate.example.com
   DOMAIN=affiliate.example.com
   K8S_NAMESPACE=social-affiliate
   APP_BIND=10.42.0.1:8090       # cluster bridge only; the server has no firewall
   DB_CONNECTION=pgsql
   DB_PORT=5432
   DB_DATABASE=social_affiliate
   DB_USERNAME=social_affiliate
   DB_PASSWORD=                  # random, also used for the role below
   SHARED_POSTGRES_IP=           # kubectl -n data get svc postgres -o jsonpath='{.spec.clusterIP}'
   QUEUE_CONNECTION=redis
   CACHE_STORE=redis
   SESSION_DRIVER=database
   ```

2. Create the database and role (per `~/apps/services/README.md`), piping the SQL into
   `kubectl -n data exec -i postgres-0 -- psql -U <superuser> -d postgres`:

   ```sql
   CREATE ROLE social_affiliate LOGIN PASSWORD '...' CONNECTION LIMIT 20;
   ALTER ROLE social_affiliate SET statement_timeout = '30s';
   CREATE DATABASE social_affiliate OWNER social_affiliate;
   REVOKE CONNECT ON DATABASE social_affiliate FROM PUBLIC;
   GRANT CONNECT ON DATABASE social_affiliate TO pgexporter;
   ```

3. Point DNS at the server (Cloudflare, proxied) and run `./deploy/k3s/deploy.sh`.

4. Start the stack after reboots (Docker starts before k3s creates the bridge):

   ```
   crontab -e
   @reboot /home/<user>/apps/social-affiliate/deploy/k3s/start-after-boot.sh
   ```

5. Create the admin login: `docker compose --env-file .env -f deploy/k3s/compose.yaml exec app php artisan make:filament-user`

6. Telegram: set `TELEGRAM_*` in `.env`, redeploy, then
   `docker compose --env-file .env -f deploy/k3s/compose.yaml exec app php artisan telegram:set-webhook`.

## Redeploy

```
./deploy/k3s/deploy.sh
```

## Checks

```
kubectl -n social-affiliate get endpointslice,ingress,certificate
curl -s http://10.42.0.1:8090/up            # the app alone
curl -sI https://affiliate.example.com/up   # through Cloudflare and Traefik
```

If `SHARED_POSTGRES_IP` changes (the `postgres` Service was recreated), update `.env` and redeploy.
