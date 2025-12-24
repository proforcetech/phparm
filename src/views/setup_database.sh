#!/bin/bash

echo "================================"
echo "PHPArm Database Setup Script"
echo "================================"
echo ""

# Database configuration
DB_NAME="phparm"
DB_USER="phparm_user"
DB_PASS=$(openssl rand -base64 16 | tr -d "=+/" | cut -c1-16)

echo "Creating database and user..."
echo ""

# Create database and user
sudo mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};" 2>/dev/null
sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null
sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';" 2>/dev/null
sudo mysql -e "FLUSH PRIVILEGES;" 2>/dev/null

echo "✓ Database created: ${DB_NAME}"
echo "✓ User created: ${DB_USER}"
echo "✓ Password: ${DB_PASS}"
echo ""

# Create .env file
if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env
    
    # Update database credentials
    sed -i "s/DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    sed -i "s/DB_USERNAME=.*/DB_USERNAME=${DB_USER}/" .env
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
    
    # Generate JWT secret
    JWT_SECRET=$(php -r "echo bin2hex(random_bytes(32));" 2>/dev/null || openssl rand -hex 32)
    sed -i "s/JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET}/" .env
    
    echo "✓ .env file created"
else
    echo "⚠ .env file already exists, updating database credentials..."
    sed -i "s/DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" .env
    sed -i "s/DB_USERNAME=.*/DB_USERNAME=${DB_USER}/" .env
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
fi

echo ""
echo "================================"
echo "Setup Complete!"
echo "================================"
echo ""
echo "Database: ${DB_NAME}"
echo "Username: ${DB_USER}"
echo "Password: ${DB_PASS}"
echo ""
echo "To run migrations, execute:"
echo "  php migrate.php"
echo ""
echo "To start the server, execute:"
echo "  php -S localhost:8000 -t public"
echo ""
