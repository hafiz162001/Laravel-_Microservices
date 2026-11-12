# Enterprise Polyglot Microservices (Laravel + Go)

Proyek arsitektur microservices modern berstandar industri dengan **Traefik API Gateway**, **Laravel 13** (Auth & Order), **Go (Golang)** (gRPC Inventory Server & RabbitMQ Notification Worker), **gRPC / Protocol Buffers**, **Redis** (Caching & Rate Limiting), **PostgreSQL**, **RabbitMQ**, **Prometheus + Grafana** (Observability), **Kubernetes (`k8s/`)**, dan **CI/CD GitHub Actions**.

---

## 🏛️ Arsitektur Sistem

```
                        +----------------------+
                        |   Client / Browser   |
                        +----------+-----------+
                                   | (Port 80)
                                   v
                        +----------+-----------+
                        |   Traefik Gateway    | <--- Dashboard: :8080
                        | (Routing, RateLimit) |
                        +----+------+------+---+
                             |      |      |
        +--------------------+      |      +--------------------+
        | /api/auth/*               | /api/orders/*             | /api/notifications/*
        v                           v                           v
+-------+--------+          +-------+--------+          +-------+--------+
|  auth-service  |          |  order-service |          | notification-  |
|   (Laravel)    |          |   (Laravel)    |          | service (Go)   |
+---+--------+---+          +---+----+---+---+          +-------+--------+
    |        |                  |    |   |                      ^
    v        |                  |    |   +---(Publish Event)----+
+---+---+    +-------->+--------+    |        RabbitMQ (:5672 / UI :15672)
|auth_db|              v             |
| (PG)  |          +---+---+         v
+-------+          | Redis |     +---+----+
                   |(:6379)|     |order_db|
                   +-------+     |  (PG)  |
                                 +--------+

   [ Monitoring Stack: Prometheus (:9090) ---> Grafana Dashboard (:3000) ]
```

---

## 🚀 Panduan Menjalankan (Docker Compose)

Pastikan **Docker Desktop** berjalan, lalu di folder `c:\Dishub\Experimen\laravel-microservices` jalankan:

```bash
docker compose up -d --build
```

### Akses Dashboard & Layanan

| Layanan | URL | Keterangan |
| :--- | :--- | :--- |
| **API Gateway / Frontend** | `http://localhost` | Pintu masuk utama seluruh API |
| **Traefik Dashboard** | `http://localhost:8080` | Monitoring routing & rate limiting gateway |
| **Grafana Dashboard** | `http://localhost:3000` | Visualisasi metrik real-time (login: `admin` / `admin`) |
| **Prometheus** | `http://localhost:9090` | Pengumpul metrik & status scraping |
| **RabbitMQ Management** | `http://localhost:15672` | Pantau antrean pesan (login: `guest` / `guest`) |

---

## 📡 Daftar Endpoint API (Melalui Traefik Gateway `:80`)

| Method | Endpoint | Service | Fitur Enterprise |
| :--- | :--- | :--- | :--- |
| `GET` | `/healthz` | **Gateway** | Cek status Traefik + Nginx |
| `POST` | `/api/auth/register` | **auth-service (Laravel)** | Register ke PostgreSQL + Rate Limiting |
| `POST` | `/api/auth/login` | **auth-service (Laravel)** | Login & buat token Stateless JWT |
| `GET` | `/api/auth/profile` | **auth-service (Laravel)** | Decode JWT profil user |
| `GET` | `/api/orders/stock?item=MacBook%20Pro%20M3` | **order-service $\rightarrow$ inventory-service** | Cek stok secara sinkron melalui **gRPC (HTTP/2 + Protobuf)** |
| `GET` | `/api/orders/` | **order-service (Laravel)** | Verifikasi JWT stateless + **Redis Caching (`X-Cache: MISS/HIT`)** |
| `POST` | `/api/orders/` | **order-service $\rightarrow$ gRPC + RabbitMQ** | Reservasi stok via **gRPC** + invalidasi cache Redis + **Publish ke RabbitMQ** |
| `GET` | `/api/notifications/` | **notification-service (Go)** | Daftar notifikasi yang dikonsumsi asinkron dari RabbitMQ |
| `GET` | `/api/*/metrics` | **Semua Service** | Endpoint metrik format Prometheus |
| `gRPC` | `localhost:50051` | **inventory-service (Go)** | Native gRPC Server (`proto/inventory.proto`, dengan Server Reflection) |

---

## ☸️ Kubernetes (`k8s/`)

Manifest Kubernetes lengkap tersedia di folder [`k8s/`](k8s/) meliputi:
* `namespace.yaml`, `configmap.yaml` (ConfigMap & Secret)
* `infrastructure.yaml` (PostgreSQL, Redis, RabbitMQ)
* `auth-service.yaml` & `order-service.yaml` (Deployment, Liveness/Readiness Probe, **HorizontalPodAutoscaler**)
* `notification-service.yaml` & `ingress.yaml`
* Tervalidasi otomatis di GitHub Actions menggunakan `kubeconform`.
