FROM php:8.1-apache

# Copy app files
COPY . /var/www/html/

# Copy and set up entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Run entrypoint to generate config.php from env‑vars
ENTRYPOINT ["/entrypoint.sh"]

# Start Apache
CMD ["apache2-foreground"]
