# VS Bus Booking Manager

A comprehensive ticket and bus seat booking plugin for WooCommerce and WordPress, featuring graphical seat selection and blacklist management.

## Description

سیستم رزرواسیون صندلی اتوبوس با انتخاب گرافیکی و لیست سیاه برای ووکامرس و وردپرس

This plugin provides a complete bus seat reservation system with:
- Graphical seat selection interface
- Blacklist management for problematic customers
- WooCommerce integration
- Admin dashboard for managing bookings
- Seat availability tracking
- AJAX-powered booking process

## Features

- **Graphical Seat Selection**: Interactive seat map for easy booking
- **Blacklist System**: Block customers who violate terms
- **WooCommerce Integration**: Seamless e-commerce functionality
- **Admin Interface**: Comprehensive management dashboard
- **Booking Reports**: Track and analyze reservations
- **Responsive Design**: Works on all devices
- **Electronic Tickets**: PDF tickets with QR codes
- **Email Notifications**: Automated customer and admin notifications
- **Real-time Reservations**: Database-backed seat reservations

## 🚀 Performance

Version 1.9.2 includes enhanced performance and compatibility:

- **Smart Caching**: Up to 70% reduction in seat loading time
- **Database Optimization**: Advanced indexes for high-speed queries
- **AJAX Compression**: Gzip-compressed responses for faster interactions
- **Memory Management**: Efficient resource usage for high-traffic sites
- **Query Optimization**: Reduced database load by 50% for repeated data
- **Theme Compatibility**: Improved hooks for better theme integration

## Installation

1. Upload the `vs-bus-booking-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings in the admin panel
4. Add seat booking to your WooCommerce products

## Changelog

### Version 1.9.2 (Latest)
- **Bug Fixes**
  - Fixed critical product display issue with seat selector
  - Improved theme compatibility with better WooCommerce hooks
  - Enhanced error handling and debugging capabilities

- **New Features**
  - Advanced seat layout configurations (2-2-2, 2-3-2, 1-2, VIP, with stairs)
  - Custom visual editor placeholder for future seat layout designer
  - Improved admin interface for seat management

- **Technical Improvements**
  - Changed display hook to `woocommerce_single_product_summary` for better compatibility
  - Added comprehensive debug logging for troubleshooting
  - Code cleanup and syntax error fixes

### Version 1.9.1
- **Performance Optimizations**
  - Advanced caching system with transient storage
  - Database query optimization with new indexes
  - AJAX response compression with gzip
  - Up to 70% improvement in loading times
  - Reduced database queries by 50%

### Version 1.9.0
- **Advanced Order Management**
  - Enhanced bookings admin page with bulk operations
  - Advanced filtering by service, status, date range, and search
  - Bulk status updates for multiple bookings
  - Service column in bookings table for better organization
  - Improved user interface with select-all functionality
  - Quick access to reservation management and order editing

### Version 1.8.0
- **Email Notification System**
  - Comprehensive email notifications for customers and admins
  - Customizable HTML email templates with Persian/RTL support
  - Booking confirmation emails for customers
  - Booking cancellation emails with refund information
  - Admin notifications for new bookings and expired reservations
  - Configurable email settings and sender information
  - BCC functionality for admin oversight

### Version 1.7.0
- **Real Seat Reservation System**
  - Implemented proper seat reservation with database tracking
  - Added reservation states: reserved, confirmed, cancelled, expired
  - Automatic cleanup of expired reservations
  - Admin interface for managing reservations
  - Real-time seat availability checking
  - Order status integration for reservation management

### Version 1.6.0
- **Bug Fixes and UX Improvements**
  - Fixed duplicate seat selection displays
  - Resolved JavaScript scoping issues with seat selection
  - Hidden WooCommerce default add to cart button and quantity selector
  - Switched to AJAX-only cart addition for better reliability
  - Code cleanup and removal of unused hooks

### Version 1.4.0
- Initial release with core booking functionality
- Basic seat selection and blacklist features

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.0+

## Support

For support and feature requests, visit [VernaSoft](https://vernasoft.ir)

## License

GPL v2 or later
