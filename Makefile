.PHONY: up down load-test load-test-bulk

# Setup commands
up:
	docker-compose up -d --build

down:
	docker-compose down

# Load Testing commands
load-test:
	go run ../scripts/loadtest.go -mode=single -url=http://localhost:8000

load-test-bulk:
	go run ../scripts/loadtest.go -mode=bulk -url=http://localhost:8000
