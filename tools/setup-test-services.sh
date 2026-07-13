#!/bin/bash
set -e

# Create directories
mkdir -p tests/certs
mkdir -p /tmp/redis-socket
chmod 777 /tmp/redis-socket

# Generate certificates
openssl genrsa -out tests/certs/ca.key 2048
openssl req -x509 -new -nodes -key tests/certs/ca.key -sha256 -days 365 -subj "/CN=Test CA" -out tests/certs/ca.crt

openssl genrsa -out tests/certs/redis.key 2048
openssl req -new -key tests/certs/redis.key -subj "/CN=127.0.0.1" -out tests/certs/redis.csr

echo "subjectAltName = IP:127.0.0.1" > tests/certs/ext.conf
openssl x509 -req -in tests/certs/redis.csr -CA tests/certs/ca.crt -CAkey tests/certs/ca.key -CAcreateserial -out tests/certs/redis.crt -days 365 -sha256 -extfile tests/certs/ext.conf

openssl genrsa -out tests/certs/untrusted-ca.key 2048
openssl req -x509 -new -nodes -key tests/certs/untrusted-ca.key -sha256 -days 365 -subj "/CN=Untrusted CA" -out tests/certs/untrusted-ca.crt

chmod -R 777 tests/certs

# Stop existing containers if any
docker rm -f redis-unix redis-acl redis-tls || true

# Start containers
docker run -d --name redis-unix -v /tmp/redis-socket:/tmp redis:8-alpine redis-server --unixsocket /tmp/redis.sock --unixsocketperm 777 --port 0
docker run -d --name redis-acl -p 6381:6379 redis:8-alpine redis-server
docker run -d --name redis-tls -p 6382:6382 -v $(pwd)/tests/certs:/certs redis:8-alpine redis-server --port 0 --tls-port 6382 --tls-cert-file /certs/redis.crt --tls-key-file /certs/redis.key --tls-ca-cert-file /certs/ca.crt --tls-auth-clients no

# Wait for startup
sleep 2

# Configure ACL user
docker exec redis-acl redis-cli ACL SETUSER myuser on ">mypassword" "~*" "+@all"
echo "Connection scenarios test services started successfully."
