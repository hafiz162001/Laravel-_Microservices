# Stage 1: Build Go binary
FROM golang:1.23-alpine AS builder

WORKDIR /app

COPY go.mod ./
COPY main.go ./

RUN go mod tidy && CGO_ENABLED=0 GOOS=linux go build -o notification-service .

# Stage 2: Minimal runtime image (~15MB)
FROM alpine:3.20

WORKDIR /app
COPY --from=builder /app/notification-service .

EXPOSE 8003

CMD ["./notification-service"]

