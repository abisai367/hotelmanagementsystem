# M-Pesa STK Push Setup Guide

## Overview
The payment system uses M-Pesa STK Push to initiate payment prompts on customer phones. This requires credentials from Safaricom's M-Pesa Developer Portal.

## Getting Your Credentials

1. **Register as a Developer**
   - Go to https://developer.safaricom.co.ke
   - Create account or login
   - Complete business verification

2. **Create an App**
   - Navigate to "My Apps" 
   - Create a new app with name like "Five Star Hotel"
   - Select appropriate sandbox or production environment

3. **Get Your Keys**
   - **Consumer Key** - unique identifier for your app
   - **Consumer Secret** - secret key for authentication
   - **Business Short Code** - usually provided (test: 174379)
   - **Passkey** - encryption key for STK requests

4. **Set Callback URL**
   - Must be a **publicly accessible HTTPS URL**
   - Example: `https://fivestarhotel.rf.gd/api/callback.php`
   - Configure this in your app settings on the developer portal

## Configuration Methods

### Method 1: Environment Variables (Recommended)
Create a `.env` file in your project root:
```env
MPESA_CONSUMER_KEY=your_consumer_key_here
MPESA_CONSUMER_SECRET=your_consumer_secret_here
MPESA_BUSINESS_CODE=174379
MPESA_PASSKEY=your_passkey_here
MPESA_CALLBACK_URL=https://yourdomain.com/api/callback.php
```

Then load it in your PHP (if using vlucas/dotenv):
```php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
```

### Method 2: Direct Configuration (Quick Setup)
Edit `api/stkpush.php` and replace:
```php
$consumerKey    = "YOUR_CONSUMER_KEY_HERE";
$consumerSecret = "YOUR_CONSUMER_SECRET_HERE";
$BusinessShortCode = "174379"; // Test short code
$Passkey = "YOUR_PASSKEY_HERE";
$callbackUrl = "https://fivestarhotel.rf.gd/api/callback.php";
```

## Testing

1. **Sandbox Testing**
   - Use test phone: 254708374149 or 254701234567
   - Use small amounts for testing (e.g., KES 1)
   - Check `stk_debug.json` for request/response logs

2. **Production Readiness**
   - Switch credentials to production values
   - Ensure callback URL is live and working
   - Test with real M-Pesa account

## Troubleshooting

### "Failed to get access token"
- Check Consumer Key and Secret are correct
- Verify credentials haven't expired
- Ensure app is still active in developer portal

### "Invalid phone format"
- Phone must be 12 digits starting with 254 (Kenya code)
- Accepted formats: 254712345678 or 0712345678

### "STK Push failed"
- Check business short code is correct
- Verify passkey matches what's in portal
- Ensure callback URL is HTTPS and publicly accessible

## Production Checklist

- [ ] Replace test credentials with production credentials
- [ ] Set callback URL to production domain
- [ ] Enable HTTPS for all payment endpoints
- [ ] Test full payment flow end-to-end
- [ ] Monitor `stk_debug.json` for errors
- [ ] Set up proper error logging and notifications
- [ ] Implement payment verification in callback
- [ ] Secure all API keys (use environment variables)






auto scroll effect is still there,,,,,,,,,,,,,,,,,,,,,small devices still cant see the titles or use navigation at the top and another thing small devices cant scroll through the navigation in the sidebar hey please make this posssible (SMALL DEVICES NEED TO SEE THE FOOTER ALSO AND THEY NEED TO SEE THE HEADINGS AND USE THE NAVIGATION THERE PLEASE DO THE TESTS BEFORE ACERTAING THAT IT IS NOW FUNCTIONAL ALL MY USERS ARE ON SMALLL DEVICES HELP AND ALSO CONSIDER THE WHOLE APP CSS MAKE IT FULLLY RESPONSIVE TO ALL DEVICES)