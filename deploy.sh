#!/bin/bash

#===============================================================================
# PHPArm Full Server Deployment Script
#===============================================================================
#
# This script performs a complete deployment of PHPArm on a fresh server.
# Designed for Ubuntu 22.04/24.04 LTS on Linode, DigitalOcean, or similar VPS.
#
# Features:
#   - Installs all dependencies (Apache, PHP 8.2+, MariaDB, Node.js, Composer)
#   - Configures Apache virtual host with security headers
#   - Sets up MariaDB with secure installation
#   - Configures UFW firewall
#   - Optional SSL via Let's Encrypt
#   - Creates admin user with random password
#   - Sets appropriate file permissions
#
# Usage:
#   chmod +x deploy.sh
#   sudo ./deploy.sh
#
# Options:
#   --domain=example.com     Set the domain name
#   --email=admin@example.com Set admin email for SSL certificates
#   --skip-ssl               Skip SSL certificate installation
#   --skip-firewall          Skip firewall configuration
#   --branch=main            Git branch to deploy (default: main)
#   --repo=URL               Git repository URL
#
#===============================================================================

set -e  # Exit on error

# ============================================
# Configuration Variables
# ============================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Default values
INSTALL_DIR="/var/www/phparm"
WEB_USER="www-data"
WEB_GROUP="www-data"
PHP_VERSION="8.2"
NODE_VERSION="20"
DOMAIN=""
ADMIN_EMAIL=""
SKIP_SSL=false
SKIP_FIREWALL=false
GIT_BRANCH="main"
GIT_REPO=""

# Generated values
DB_NAME="phparm"
DB_USER="phparm_user"
DB_PASS=""
ADMIN_PASSWORD=""
JWT_SECRET=""

# ============================================
# Helper Functions
# ============================================

print_header() {
    echo -e "\n${BOLD}${CYAN}════════════════════════════════════════════${NC}"
    echo -e "${BOLD}${CYAN} $1${NC}"
    echo -e "${BOLD}${CYAN}════════════════════════════════════════════${NC}\n"
}

print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${CYAN}→${NC} $1"
}

generate_password() {
    local length=${1:-16}
    openssl rand -base64 48 | tr -dc 'a-zA-Z0-9!@#$%^&*' | head -c "$length"
}

generate_secret() {
    openssl rand -hex 32
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root (use sudo)"
        exit 1
    fi
}

check_os() {
    if [[ ! -f /etc/os-release ]]; then
        print_error "Cannot detect OS. This script requires Ubuntu 22.04 or 24.04"
        exit 1
    fi

    source /etc/os-release

    if [[ "$ID" != "ubuntu" ]]; then
        print_error "This script is designed for Ubuntu. Detected: $ID"
        exit 1
    fi

    if [[ "$VERSION_ID" != "22.04" && "$VERSION_ID" != "24.04" ]]; then
        print_warning "Tested on Ubuntu 22.04 and 24.04. You have: $VERSION_ID"
    fi
}

# ============================================
# Parse Command Line Arguments
# ============================================

parse_arguments() {
    for arg in "$@"; do
        case $arg in
            --domain=*)
                DOMAIN="${arg#*=}"
                ;;
            --email=*)
                ADMIN_EMAIL="${arg#*=}"
                ;;
            --skip-ssl)
                SKIP_SSL=true
                ;;
            --skip-firewall)
                SKIP_FIREWALL=true
                ;;
            --branch=*)
                GIT_BRANCH="${arg#*=}"
                ;;
            --repo=*)
                GIT_REPO="${arg#*=}"
                ;;
            --help)
                show_help
                exit 0
                ;;
            *)
                print_warning "Unknown argument: $arg"
                ;;
        esac
    done
}

show_help() {
    echo "PHPArm Deployment Script"
    echo ""
    echo "Usage: sudo ./deploy.sh [options]"
    echo ""
    echo "Options:"
    echo "  --domain=DOMAIN        Set the domain name (e.g., phparm.example.com)"
    echo "  --email=EMAIL          Admin email for SSL certificates"
    echo "  --skip-ssl             Skip Let's Encrypt SSL installation"
    echo "  --skip-firewall        Skip UFW firewall configuration"
    echo "  --branch=BRANCH        Git branch to deploy (default: main)"
    echo "  --repo=URL             Git repository URL to clone"
    echo "  --help                 Show this help message"
}

# ============================================
# Interactive Prompts
# ============================================

interactive_setup() {
    print_header "Interactive Setup"

    # Domain
    if [[ -z "$DOMAIN" ]]; then
        read -p "Enter domain name (or press Enter for IP-based access): " DOMAIN
    fi

    # Admin email
    if [[ -z "$ADMIN_EMAIL" ]]; then
        read -p "Enter admin email address: " ADMIN_EMAIL
    fi

    # SSL
    if [[ -n "$DOMAIN" && "$SKIP_SSL" == false ]]; then
        read -p "Install SSL certificate via Let's Encrypt? [Y/n]: " ssl_choice
        if [[ "$ssl_choice" =~ ^[Nn]$ ]]; then
            SKIP_SSL=true
        fi
    fi

    # Git repo
    if [[ -z "$GIT_REPO" ]]; then
        read -p "Git repository URL (or press Enter to skip git clone): " GIT_REPO
    fi
}

# ============================================
# System Updates
# ============================================

update_system() {
    print_header "Updating System Packages"

    apt-get update -y
    apt-get upgrade -y
    apt-get install -y software-properties-common curl wget gnupg2 ca-certificates lsb-release apt-transport-https

    print_status "System packages updated"
}

# ============================================
# Install Apache
# ============================================

install_apache() {
    print_header "Installing Apache Web Server"

    apt-get install -y apache2

    # Enable required modules
    a2enmod rewrite
    a2enmod headers
    a2enmod ssl
    a2enmod expires
    a2enmod deflate

    # Disable default site
    a2dissite 000-default.conf 2>/dev/null || true

    # Security hardening
    cat > /etc/apache2/conf-available/security-hardening.conf << 'EOF'
# Security Headers
ServerTokens Prod
ServerSignature Off
TraceEnable Off

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
EOF

    a2enconf security-hardening

    systemctl enable apache2
    systemctl start apache2

    print_status "Apache installed and configured"
}

# ============================================
# Install PHP
# ============================================

install_php() {
    print_header "Installing PHP ${PHP_VERSION}"

    # Add PHP repository
    add-apt-repository -y ppa:ondrej/php

    apt-get update -y

    # Install PHP and extensions
    apt-get install -y \
        php${PHP_VERSION} \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-common \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-pdo \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-zip \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-opcache \
        php${PHP_VERSION}-readline \
        libapache2-mod-php${PHP_VERSION}

    # Configure PHP
    PHP_INI="/etc/php/${PHP_VERSION}/apache2/php.ini"

    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 64M/' "$PHP_INI"
    sed -i 's/post_max_size = .*/post_max_size = 64M/' "$PHP_INI"
    sed -i 's/memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
    sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$PHP_INI"
    sed -i 's/;date.timezone =.*/date.timezone = UTC/' "$PHP_INI"
    sed -i 's/expose_php = .*/expose_php = Off/' "$PHP_INI"

    # Enable OPcache
    cat > /etc/php/${PHP_VERSION}/mods-available/opcache-custom.ini << 'EOF'
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.save_comments=1
EOF

    systemctl restart apache2

    print_status "PHP ${PHP_VERSION} installed and configured"
}

# ============================================
# Install MariaDB
# ============================================

install_mariadb() {
    print_header "Installing MariaDB"

    apt-get install -y mariadb-server mariadb-client

    systemctl enable mariadb
    systemctl start mariadb

    # Generate database password
    DB_PASS=$(generate_password 24)

    # Secure MariaDB installation
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -u root -p"${DB_PASS}" << EOF
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF

    # Create application database and user
    mysql -u root -p"${DB_PASS}" << EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

    print_status "MariaDB installed and configured"
    print_info "Database: ${DB_NAME}"
    print_info "Username: ${DB_USER}"
}

# ============================================
# Install Node.js
# ============================================

install_nodejs() {
    print_header "Installing Node.js ${NODE_VERSION}"

    # Install Node.js from NodeSource
    curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
    apt-get install -y nodejs

    # Verify installation
    node_version=$(node -v)
    npm_version=$(npm -v)

    print_status "Node.js ${node_version} installed"
    print_status "npm ${npm_version} installed"
}

# ============================================
# Install Composer
# ============================================

install_composer() {
    print_header "Installing Composer"

    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        print_error "Composer installer signature mismatch"
        rm composer-setup.php
        exit 1
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php

    # Verify installation
    composer_version=$(composer --version)
    print_status "Composer installed: ${composer_version}"
}

# ============================================
# Install Additional Tools
# ============================================

install_tools() {
    print_header "Installing Additional Tools"

    apt-get install -y \
        git \
        unzip \
        acl \
        supervisor \
        cron

    print_status "Additional tools installed"
}

# ============================================
# Deploy Application
# ============================================

deploy_application() {
    print_header "Deploying PHPArm Application"

    # Create installation directory
    mkdir -p "$INSTALL_DIR"

    if [[ -n "$GIT_REPO" ]]; then
        # Clone from repository
        print_info "Cloning from ${GIT_REPO}..."
        git clone --branch "$GIT_BRANCH" "$GIT_REPO" "$INSTALL_DIR"
    else
        # Check if files exist in current directory
        if [[ -f "./composer.json" ]]; then
            print_info "Copying files from current directory..."
            cp -r ./* "$INSTALL_DIR/"
            cp -r ./.env.example "$INSTALL_DIR/" 2>/dev/null || true
            cp -r ./.gitignore "$INSTALL_DIR/" 2>/dev/null || true
        else
            print_warning "No git repo specified and no local files found."
            print_info "Please manually copy your application files to ${INSTALL_DIR}"
        fi
    fi

    cd "$INSTALL_DIR"

    # Install PHP dependencies
    if [[ -f "composer.json" ]]; then
        print_info "Installing PHP dependencies..."
        composer install --no-dev --optimize-autoloader --no-interaction
        print_status "PHP dependencies installed"
    fi

    # Install Node dependencies and build
    if [[ -f "package.json" ]]; then
        print_info "Installing Node.js dependencies..."
        npm ci --production=false
        print_status "Node.js dependencies installed"

        print_info "Building frontend assets..."
        npm run build
        print_status "Frontend assets built"
    fi

    print_status "Application deployed to ${INSTALL_DIR}"
}

# ============================================
# Configure Environment
# ============================================

configure_environment() {
    print_header "Configuring Environment"

    cd "$INSTALL_DIR"

    # Generate secrets
    JWT_SECRET=$(generate_secret)
    ADMIN_PASSWORD=$(generate_password 16)

    # Create .env from template
    if [[ -f ".env.example" ]]; then
        cp .env.example .env

        # Update .env values
        sed -i "s|APP_ENV=.*|APP_ENV=production|" .env
        sed -i "s|APP_DEBUG=.*|APP_DEBUG=false|" .env
        sed -i "s|APP_URL=.*|APP_URL=https://${DOMAIN:-localhost}|" .env

        sed -i "s|DB_HOST=.*|DB_HOST=localhost|" .env
        sed -i "s|DB_PORT=.*|DB_PORT=3306|" .env
        sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
        sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
        sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env

        sed -i "s|JWT_SECRET=.*|JWT_SECRET=${JWT_SECRET}|" .env

        # Set file permissions
        chmod 600 .env

        print_status ".env file configured"
    else
        print_error ".env.example not found!"
    fi
}

# ============================================
# Install Database Schema
# ============================================

install_database() {
    print_header "Installing Database Schema"

    cd "$INSTALL_DIR"

    if [[ -f "database/install/install.sql" ]]; then
        print_info "Running database installation..."

        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < database/install/install.sql 2>/dev/null || true

        # Create migrations tracking table
        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << 'EOF'
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOF

        # Mark all migrations as executed
        for file in database/migrations/*.sql; do
            if [[ -f "$file" ]]; then
                migration_name=$(basename "$file")
                mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
                    "INSERT IGNORE INTO migrations (migration) VALUES ('${migration_name}');" 2>/dev/null || true
            fi
        done

        print_status "Database schema installed"
    elif [[ -f "install_db.php" ]]; then
        print_info "Running install_db.php..."
        php install_db.php --force
        print_status "Database installed via install_db.php"
    else
        print_warning "No install.sql found. Database must be installed manually."
    fi
}

# ============================================
# Create Admin User
# ============================================

create_admin_user() {
    print_header "Creating Admin User"

    cd "$INSTALL_DIR"

    # Hash password using PHP
    HASHED_PASSWORD=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT, ['cost' => 12]);")

    # Insert admin user
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << EOF
INSERT INTO users (name, email, password, role, active, email_verified, created_at, updated_at)
VALUES ('Administrator', '${ADMIN_EMAIL:-admin@localhost}', '${HASHED_PASSWORD}', 'admin', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    password = '${HASHED_PASSWORD}',
    role = 'admin',
    active = 1,
    updated_at = NOW();
EOF

    print_status "Admin user created"

    # Save credentials to file
    cat > /var/www/.user << EOF
PHPArm Admin Credentials
========================
Generated: $(date)

URL: https://${DOMAIN:-localhost}
Email: ${ADMIN_EMAIL:-admin@localhost}
Password: ${ADMIN_PASSWORD}

Database:
  Host: localhost
  Name: ${DB_NAME}
  User: ${DB_USER}
  Password: ${DB_PASS}
EOF

    chmod 600 /var/www/.user
    print_status "Credentials saved to /var/www/.user"
}

# ============================================
# Configure Apache Virtual Host
# ============================================

configure_apache() {
    print_header "Configuring Apache Virtual Host"

    SERVER_NAME="${DOMAIN:-$(hostname -I | awk '{print $1}')}"

    cat > /etc/apache2/sites-available/phparm.conf << EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    ServerAdmin ${ADMIN_EMAIL:-webmaster@localhost}
    DocumentRoot ${INSTALL_DIR}/public

    <Directory ${INSTALL_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Logging
    ErrorLog \${APACHE_LOG_DIR}/phparm_error.log
    CustomLog \${APACHE_LOG_DIR}/phparm_access.log combined

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"

    # Compression
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json
    </IfModule>

    # Cache static assets
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType image/jpg "access plus 1 year"
        ExpiresByType image/jpeg "access plus 1 year"
        ExpiresByType image/png "access plus 1 year"
        ExpiresByType image/gif "access plus 1 year"
        ExpiresByType image/svg+xml "access plus 1 year"
        ExpiresByType text/css "access plus 1 month"
        ExpiresByType application/javascript "access plus 1 month"
    </IfModule>
</VirtualHost>
EOF

    a2ensite phparm.conf
    systemctl reload apache2

    print_status "Apache virtual host configured"
}

# ============================================
# Set File Permissions
# ============================================

set_permissions() {
    print_header "Setting File Permissions"

    cd "$INSTALL_DIR"

    # Set ownership
    chown -R "$WEB_USER:$WEB_GROUP" "$INSTALL_DIR"

    # Set directory permissions
    find "$INSTALL_DIR" -type d -exec chmod 755 {} \;

    # Set file permissions
    find "$INSTALL_DIR" -type f -exec chmod 644 {} \;

    # Make scripts executable
    chmod +x "$INSTALL_DIR"/*.php 2>/dev/null || true
    chmod +x "$INSTALL_DIR"/*.sh 2>/dev/null || true

    # Secure sensitive files
    chmod 600 "$INSTALL_DIR/.env" 2>/dev/null || true

    # Writable directories
    mkdir -p "$INSTALL_DIR/storage"
    mkdir -p "$INSTALL_DIR/storage/logs"
    mkdir -p "$INSTALL_DIR/storage/uploads"
    mkdir -p "$INSTALL_DIR/storage/cache"

    chmod -R 775 "$INSTALL_DIR/storage"
    chown -R "$WEB_USER:$WEB_GROUP" "$INSTALL_DIR/storage"

    # Set ACL for storage directory
    setfacl -R -m u:${WEB_USER}:rwX "$INSTALL_DIR/storage" 2>/dev/null || true
    setfacl -R -d -m u:${WEB_USER}:rwX "$INSTALL_DIR/storage" 2>/dev/null || true

    print_status "File permissions configured"
}

# ============================================
# Configure Firewall
# ============================================

configure_firewall() {
    if [[ "$SKIP_FIREWALL" == true ]]; then
        print_warning "Skipping firewall configuration"
        return
    fi

    print_header "Configuring UFW Firewall"

    # Install UFW if not present
    apt-get install -y ufw

    # Reset UFW
    ufw --force reset

    # Default policies
    ufw default deny incoming
    ufw default allow outgoing

    # Allow SSH
    ufw allow ssh

    # Allow HTTP and HTTPS
    ufw allow 'Apache Full'

    # Enable UFW
    echo "y" | ufw enable

    print_status "Firewall configured"
    ufw status verbose
}

# ============================================
# Install SSL Certificate
# ============================================

install_ssl() {
    if [[ "$SKIP_SSL" == true ]] || [[ -z "$DOMAIN" ]]; then
        print_warning "Skipping SSL installation"
        return
    fi

    print_header "Installing SSL Certificate"

    # Install Certbot
    apt-get install -y certbot python3-certbot-apache

    # Obtain certificate
    certbot --apache \
        --non-interactive \
        --agree-tos \
        --email "${ADMIN_EMAIL:-admin@${DOMAIN}}" \
        -d "$DOMAIN" \
        --redirect

    # Setup auto-renewal
    systemctl enable certbot.timer
    systemctl start certbot.timer

    print_status "SSL certificate installed for ${DOMAIN}"
}

# ============================================
# Configure Log Rotation
# ============================================

configure_logrotate() {
    print_header "Configuring Log Rotation"

    cat > /etc/logrotate.d/phparm << EOF
${INSTALL_DIR}/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 ${WEB_USER} ${WEB_GROUP}
    sharedscripts
}
EOF

    print_status "Log rotation configured"
}

# ============================================
# Setup Cron Jobs
# ============================================

setup_cron() {
    print_header "Setting Up Scheduled Tasks"

    # Create cron job for application scheduler (if exists)
    if [[ -f "${INSTALL_DIR}/cron.php" ]]; then
        (crontab -l 2>/dev/null; echo "* * * * * cd ${INSTALL_DIR} && php cron.php >> /dev/null 2>&1") | crontab -
        print_status "Cron job added for application scheduler"
    fi

    # Backup cron (daily at 2 AM)
    cat > /etc/cron.d/phparm-backup << EOF
0 2 * * * root mysqldump -u ${DB_USER} -p'${DB_PASS}' ${DB_NAME} | gzip > /var/backups/phparm_\$(date +\%Y\%m\%d).sql.gz
EOF

    chmod 644 /etc/cron.d/phparm-backup

    # Create backup directory
    mkdir -p /var/backups

    print_status "Scheduled tasks configured"
}

# ============================================
# Final Summary
# ============================================

print_summary() {
    print_header "Installation Complete!"

    echo -e "${GREEN}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                                                           ║"
    echo "║   PHPArm has been successfully deployed!                  ║"
    echo "║                                                           ║"
    echo "╚═══════════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    echo ""
    echo -e "${BOLD}Access Details:${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    if [[ -n "$DOMAIN" ]] && [[ "$SKIP_SSL" == false ]]; then
        echo -e "  URL:           ${CYAN}https://${DOMAIN}${NC}"
    else
        echo -e "  URL:           ${CYAN}http://${DOMAIN:-$(hostname -I | awk '{print $1}')}${NC}"
    fi

    echo -e "  Admin Email:   ${CYAN}${ADMIN_EMAIL:-admin@localhost}${NC}"
    echo -e "  Admin Password: ${YELLOW}${ADMIN_PASSWORD}${NC}"
    echo ""
    echo -e "${BOLD}Database:${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "  Host:          localhost"
    echo -e "  Database:      ${DB_NAME}"
    echo -e "  Username:      ${DB_USER}"
    echo -e "  Password:      ${YELLOW}${DB_PASS}${NC}"
    echo ""
    echo -e "${BOLD}Important Files:${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "  Installation:  ${INSTALL_DIR}"
    echo -e "  Credentials:   /var/www/.user"
    echo -e "  Environment:   ${INSTALL_DIR}/.env"
    echo -e "  Apache Config: /etc/apache2/sites-available/phparm.conf"
    echo ""
    echo -e "${YELLOW}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${YELLOW}║  IMPORTANT: Save the credentials above in a secure place! ║${NC}"
    echo -e "${YELLOW}║  They are also saved in /var/www/.user                    ║${NC}"
    echo -e "${YELLOW}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BOLD}Next Steps:${NC}"
    echo "  1. Login at the URL above with admin credentials"
    echo "  2. Update business settings in the admin panel"
    echo "  3. Configure email settings if needed"
    echo "  4. Add additional users as needed"
    echo ""
    echo -e "${BOLD}Maintenance Commands:${NC}"
    echo "  Upgrade database:  cd ${INSTALL_DIR} && php upgrade.php"
    echo "  View logs:         tail -f ${INSTALL_DIR}/storage/logs/*.log"
    echo "  Restart Apache:    systemctl restart apache2"
    echo ""
}

# ============================================
# Main Installation Process
# ============================================

main() {
    echo -e "${BOLD}${GREEN}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                                                           ║"
    echo "║        PHPArm Full Server Deployment Script               ║"
    echo "║                                                           ║"
    echo "╚═══════════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    # Pre-flight checks
    check_root
    check_os

    # Parse command line arguments
    parse_arguments "$@"

    # Interactive setup if needed
    interactive_setup

    # Installation steps
    update_system
    install_apache
    install_php
    install_mariadb
    install_nodejs
    install_composer
    install_tools
    deploy_application
    configure_environment
    install_database
    create_admin_user
    configure_apache
    set_permissions
    configure_firewall
    install_ssl
    configure_logrotate
    setup_cron

    # Final restart
    systemctl restart apache2

    # Show summary
    print_summary
}

# Run main function
main "$@"
