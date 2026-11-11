# Laravel Microservices (Greenfield)

Proyek eksperimen arsitektur microservices berbasis **Laravel 11/12**, **Nginx (API Gateway)**, **PostgreSQL (Database per Service)**, dan **RabbitMQ (Message Broker)**.

---

## 🏛️ Arsitektur Sistem

```
                                +-------------------+
                                |   Client / Postman|
                                +---------+---------+
                                          |
                                    (Port 80)
                                          v
                                +---------+---------+
                                |    API Gateway    |
                                |     (Nginx)       |
                                +----+---------+----+
                                     |         |
                  /api/auth/*        |         |   /api/orders/*
            +------------------------+         +-----------------------+
            |                                                          |
            v                                                          v
  +---------+---------+                                      +---------+---------+
  |    auth-service   |                                      |   order-service   |
  |  (Laravel API)    |                                      |  (Laravel API)    |
  +---------+---------+                                      +----+---------+----+
            |                                                     |         |
            v                                                     v         v
  +---------+---------+                               +-----------+-+     +-+-------------+
  |    auth_db        |                               |   order_db  |     |   RabbitMQ    |
  |  (PostgreSQL)     |                               | (PostgreSQL)|     | (Event Broker)|
  +-------------------+                               +-------------+     +---------------+
```

---

## 🚀 Panduan Menjalankan

### 1. Menjalankan Docker Desktop
Pastikan aplikasi **Docker Desktop** di Windows Anda sudah dalam kondisi running.

### 2. Jalankan Seluruh Microservice
Buka terminal di root direktori ini (`c:\Dishub\Experimen`) dan jalankan:
Buka terminal di root direktori proyek ini (`c:\Dishub\Experimen\laravel-microservices`) dan jalankan:
```bash
docker compose up -d --build
```

Container yang akan berjalan:
* `micro_gateway` (Port 80)
* `micro_auth_service` (Internal port 8000)
* `micro_auth_db` (PostgreSQL - Port 5432)
* `micro_order_service` (Internal port 8000)
* `micro_order_db` (PostgreSQL - Port 5432)
* `micro_rabbitmq` (AMQP 5672, Dashboard Web 15672)

---

## 📡 Daftar Endpoint API (Lewat Gateway Port 80)

| Method | Endpoint | Service Tujuan | Keterangan |
| :--- | :--- | :--- | :--- |
| `GET` | `http://localhost/healthz` | **Gateway** | Cek status gateway |
| `POST` | `http://localhost/api/auth/register` | **auth-service** | Pendaftaran user baru |
| `POST` | `http://localhost/api/auth/login` | **auth-service** | Login & dapatkan token JWT |
| `GET` | `http://localhost/api/auth/profile` | **auth-service** | Cek profil user login |
| `GET` | `http://localhost/api/orders` | **order-service** | Daftar pesanan user |
| `POST` | `http://localhost/api/orders` | **order-service** | Buat pesanan baru (Triggers Event) |

---

## 🔐 Konsep Autentikasi Stateless (JWT)

1. `auth-service` membuat token JWT saat user berhasil login.
2. Token JWT dikirim oleh Client di header: `Authorization: Bearer <token>`.
3. `order-service` memiliki middleware yang memverifikasi signature JWT menggunakan shared secret `JWT_SECRET`.
4. **Hasilnya**: `order-service` mengetahui siapa `user_id` yang sedang request **tanpa perlu memanggil database `auth_db` atau API `auth-service`**.

