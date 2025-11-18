# VS Bus Booking Manager - REST API Documentation

## نمای کلی (Overview)

این API برای توسعه اپلیکیشن موبایل سیستم رزرواسیون اتوبوس VS Bus Booking Manager طراحی شده است. API از استاندارد REST پیروی می‌کند و از JWT tokens برای authentication استفاده می‌کند.

## Authentication

### دریافت Token
```
POST /wp-json/vsbbm/v1/auth/login
```

**Request Body:**
```json
{
  "username": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "username": "user",
    "email": "user@example.com",
    "display_name": "نام کاربر",
    "first_name": "نام",
    "last_name": "نام خانوادگی"
  }
}
```

### ثبت نام کاربر جدید
```
POST /wp-json/vsbbm/v1/auth/register
```

**Request Body:**
```json
{
  "username": "newuser",
  "email": "newuser@example.com",
  "password": "password123",
  "first_name": "نام",
  "last_name": "نام خانوادگی",
  "phone": "09123456789",
  "national_id": "0123456789"
}
```

## استفاده از API

تمام درخواست‌ها (به جز login/register) نیاز به header Authorization دارند:

```
Authorization: Bearer YOUR_JWT_TOKEN
```

## Endpoints

### 1. دریافت لیست محصولات (تورها)

```
GET /wp-json/vsbbm/v1/products
```

**Query Parameters:**
- `page` (int): شماره صفحه (پیش‌فرض: 1)
- `per_page` (int): تعداد آیتم در صفحه (پیش‌فرض: 10)

**Response:**
```json
{
  "success": true,
  "products": [
    {
      "id": 123,
      "name": "تهران به اصفهان",
      "description": "تور یک روزه تهران به اصفهان",
      "price": "150000",
      "regular_price": "180000",
      "sale_price": "150000",
      "image": "https://example.com/wp-content/uploads/2023/01/tour-image.jpg",
      "availability": {
        "class": "active",
        "text": "فعال",
        "description": "تا ۲۴ ساعت دیگر"
      },
      "total_seats": 32,
      "available_seats": 28
    }
  ],
  "pagination": {
    "total": 25,
    "total_pages": 3,
    "current_page": 1,
    "per_page": 10
  }
}
```

### 2. دریافت جزئیات محصول

```
GET /wp-json/vsbbm/v1/products/{id}
```

**Response:**
```json
{
  "success": true,
  "product": {
    "id": 123,
    "name": "تهران به اصفهان",
    "description": "جزئیات کامل تور...",
    "price": "150000",
    "images": [
      {
        "id": 456,
        "src": "https://example.com/image1.jpg",
        "alt": "تصویر تور"
      }
    ],
    "attributes": {
      "زمان حرکت": ["08:00", "14:00"],
      "نوع اتوبوس": ["VIP", "معمولی"]
    },
    "availability": {
      "class": "active",
      "text": "فعال",
      "description": "تا ۲۴ ساعت دیگر"
    },
    "seats": {
      "total": 32,
      "available": 28,
      "layout": [
        {"number": 1, "available": true},
        {"number": 2, "available": false}
      ]
    },
    "schedule": {
      "start_date": "2024-01-15T08:00:00",
      "end_date": "2024-01-15T18:00:00"
    }
  }
}
```

### 3. دریافت وضعیت صندلی‌ها

```
GET /wp-json/vsbbm/v1/products/{id}/seats
```

**Response:**
```json
{
  "success": true,
  "seats": [
    {
      "number": 1,
      "status": "available",
      "price": "150000"
    },
    {
      "number": 2,
      "status": "reserved",
      "price": "150000"
    }
  ]
}
```

### 4. رزرو صندلی

```
POST /wp-json/vsbbm/v1/reservations
```

**Request Body:**
```json
{
  "product_id": 123,
  "seats": [1, 2, 3],
  "passengers": [
    {
      "name": "علی احمدی",
      "national_id": "0123456789",
      "phone": "09123456789",
      "seat_number": 1
    },
    {
      "name": "مریم احمدی",
      "national_id": "0987654321",
      "phone": "09129876543",
      "seat_number": 2
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "reservation_id": 456,
  "order_id": 789,
  "order_key": "wc_order_abc123def",
  "total_amount": "450000",
  "status": "pending_payment"
}
```

### 5. دریافت رزروهای کاربر

```
GET /wp-json/vsbbm/v1/user/bookings
```

**Query Parameters:**
- `page` (int): شماره صفحه
- `per_page` (int): تعداد در صفحه

**Response:**
```json
{
  "success": true,
  "bookings": [
    {
      "id": 789,
      "order_number": "789",
      "status": "completed",
      "total": "450000",
      "currency": "IRT",
      "date_created": "2024-01-15 10:30:00",
      "product_name": "تهران به اصفهان",
      "seats": [1, 2, 3],
      "passengers": [
        {
          "name": "علی احمدی",
          "national_id": "0123456789",
          "seat_number": 1
        }
      ],
      "tickets": [
        {
          "id": 101,
          "ticket_number": "TCK2401151030ABC1",
          "passenger": {
            "name": "علی احمدی",
            "national_id": "0123456789",
            "seat_number": 1
          },
          "status": "active",
          "qr_code": "https://example.com/qr/TCK2401151030ABC1"
        }
      ]
    }
  ],
  "pagination": {
    "total": 5,
    "total_pages": 1,
    "current_page": 1,
    "per_page": 10
  }
}
```

### 6. دریافت جزئیات رزرو

```
GET /wp-json/vsbbm/v1/user/bookings/{id}
```

### 7. دریافت جزئیات بلیط

```
GET /wp-json/vsbbm/v1/tickets/{code}
```

**Response:**
```json
{
  "success": true,
  "ticket": {
    "id": 101,
    "ticket_number": "TCK2401151030ABC1",
    "order_id": 789,
    "passenger": {
      "name": "علی احمدی",
      "national_id": "0123456789",
      "seat_number": 1
    },
    "status": "active",
    "created_at": "2024-01-15 10:30:00",
    "used_at": null,
    "product_name": "#789",
    "qr_code": "https://example.com/wp-admin/admin-ajax.php?action=vsbbm_qr_code&ticket=TCK2401151030ABC1"
  }
}
```

### 8. استفاده از بلیط (اسکن QR)

```
POST /wp-json/vsbbm/v1/tickets/{code}/use
```

**Response:**
```json
{
  "success": true,
  "message": "بلیط با موفقیت استفاده شد",
  "used_at": "2024-01-15 14:30:00"
}
```

### 9. دریافت پروفایل کاربر

```
GET /wp-json/vsbbm/v1/user/profile
```

**Response:**
```json
{
  "success": true,
  "profile": {
    "id": 1,
    "username": "ali_ahmad",
    "email": "ali@example.com",
    "display_name": "علی احمدی",
    "first_name": "علی",
    "last_name": "احمدی",
    "phone": "09123456789",
    "national_id": "0123456789",
    "registered_date": "2023-12-01 10:00:00"
  }
}
```

### 10. بروزرسانی پروفایل

```
PUT /wp-json/vsbbm/v1/user/profile
```

**Request Body:**
```json
{
  "first_name": "علی",
  "last_name": "احمدی",
  "phone": "09123456789",
  "national_id": "0123456789"
}
```

## کدهای خطا (Error Codes)

### Authentication Errors
- `401`: توکن احراز هویت یافت نشد یا نامعتبر است

### Validation Errors
- `400`: داده‌های ورودی نامعتبر
- `404`: منبع یافت نشد
- `409`: تضاد داده‌ها (مثل نام کاربری تکراری)

### Business Logic Errors
- `400`: صندلی در دسترس نیست
- `400`: محصول غیرفعال است
- `500`: خطای سرور داخلی

## نمونه کد (Example Code)

### JavaScript (Fetch API)
```javascript
// Login
const login = async (username, password) => {
  const response = await fetch('/wp-json/vsbbm/v1/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ username, password })
  });

  const data = await response.json();
  if (data.success) {
    localStorage.setItem('auth_token', data.token);
  }
  return data;
};

// Get products with auth
const getProducts = async () => {
  const token = localStorage.getItem('auth_token');
  const response = await fetch('/wp-json/vsbbm/v1/products', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });

  return await response.json();
};
```

### React Native Example
```javascript
import AsyncStorage from '@react-native-async-storage/async-storage';

class ApiService {
  constructor() {
    this.baseURL = 'https://your-site.com/wp-json/vsbbm/v1';
  }

  async login(username, password) {
    const response = await fetch(`${this.baseURL}/auth/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ username, password })
    });

    const data = await response.json();
    if (data.success) {
      await AsyncStorage.setItem('auth_token', data.token);
    }
    return data;
  }

  async getProducts() {
    const token = await AsyncStorage.getItem('auth_token');
    const response = await fetch(`${this.baseURL}/products`, {
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });

    return await response.json();
  }
}

export default new ApiService();
```

## نکات مهم

1. **JWT Token**: توکن‌ها 30 روز اعتبار دارند
2. **Rate Limiting**: API دارای محدودیت نرخ درخواست است
3. **HTTPS**: همیشه از HTTPS استفاده کنید
4. **Error Handling**: همیشه پاسخ‌های خطا را چک کنید
5. **Data Validation**: داده‌های ورودی را در سمت کلاینت اعتبارسنجی کنید

## پشتیبانی

برای سوالات و مشکلات API، با تیم توسعه تماس بگیرید:
- ایمیل: support@vernasoft.ir
- وبسایت: vernasoft.ir