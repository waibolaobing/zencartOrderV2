#!/bin/bash
# ZenCart reset script - Stop containers and clean up
set -e

CODE_DIR="./code"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
print_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
print_error() { echo -e "${RED}[ERROR]${NC} $1"; }

print_warning "This will delete all local modifications and reset environment to initial state"
read -p "Are you sure you want to reset? (y/N): " confirm
if [[ ! $confirm =~ ^[Yy]$ ]]; then
  print_info "Operation cancelled"
  exit 0
fi

print_info "Stopping containers and cleaning up..."

print_info "Stopping all containers..."
docker-compose down || true

if [ -d "$CODE_DIR" ]; then
  print_info "Removing code directory..."
  rm -rf "$CODE_DIR"
fi


print_success "Reset complete!"
print_info "All containers have been stopped and environment has been reset to initial state."
print_info "Run './start.sh' to restart the project."
