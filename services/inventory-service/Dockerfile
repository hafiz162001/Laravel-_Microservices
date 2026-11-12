# Stage 1: Build Go gRPC binary
FROM golang:1.23-alpine AS builder

WORKDIR /app

COPY go.mod ./
COPY pb ./pb
COPY main.go ./

RUN go mod tidy && CGO_ENABLED=0 GOOS=linux go build -o inventory-service .

# Stage 2: Minimal runtime image
FROM alpine:3.20

WORKDIR /app
COPY --from=builder /app/inventory-service .

EXPOSE 50051 8004

CMD ["./inventory-service"]
