<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>Reset Password</title>
    
    <style>
        /* Reset styles */
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            font-family: Arial, sans-serif;
            background-color: #f9fafb;
        }
        table {
            border-collapse: collapse;
        }
        /* Windows Phone 8 Fix */
        @-ms-viewport { 
            width: device-width; 
        }
    </style>
    
    <!--[if mso]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <![endif]-->
</head>
<body style="margin:0; padding:0; background-color:#f9fafb;">
    <center>
        <!-- Container -->
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f9fafb; width: 100%;">
            <tr>
                <td align="center" style="padding: 40px 0;">
                    <!-- Content Wrapper -->
                    <table border="0" cellpadding="0" cellspacing="0" width="600" style="width: 600px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden;">
                        <!-- Header -->
                        <tr>
                            <td align="center" style="background-color: #4f46e5; padding: 30px 0;">
                                <h1 style="color: #ffffff; font-family: Arial, sans-serif; font-size: 24px; margin: 0; font-weight: bold;">
                                    AI Todo - Password Reset
                                </h1>
                            </td>
                        </tr>
                        
                        <!-- Body -->
                        <tr>
                            <td style="padding: 40px 30px;">
                                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                    <tr>
                                        <td style="color: #111827; font-family: Arial, sans-serif; font-size: 20px; font-weight: bold; padding-bottom: 20px;">
                                            Hello!
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color: #4b5563; font-family: Arial, sans-serif; font-size: 16px; line-height: 24px; padding-bottom: 20px;">
                                            You are receiving this email because we received a password reset request for your account.
                                        </td>
                                    </tr>
                                    
                                    <!-- Button -->
                                    <tr>
                                        <td align="center" style="padding: 10px 0 30px 0;">
                                            <table border="0" cellpadding="0" cellspacing="0">
                                                <tr>
                                                    <td align="center" bgcolor="#4f46e5" style="border-radius: 6px;">
                                                        <a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 14px 24px; font-family: Arial, sans-serif; font-size: 16px; color: #ffffff; text-decoration: none; font-weight: bold; background-color: #4f46e5; border-radius: 6px; border: 1px solid #4f46e5;">
                                                            Reset Password
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <td style="color: #4b5563; font-family: Arial, sans-serif; font-size: 16px; line-height: 24px; padding-bottom: 20px;">
                                            This password reset link will expire in {{ $count }} minutes.
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="color: #4b5563; font-family: Arial, sans-serif; font-size: 16px; line-height: 24px;">
                                            If you did not request a password reset, no further action is required.
                                        </td>
                                    </tr>
                                    
                                    <!-- Salutation -->
                                    <tr>
                                        <td style="color: #4b5563; font-family: Arial, sans-serif; font-size: 16px; line-height: 24px; padding-top: 30px;">
                                            Regards,<br>
                                            AI Todo Team
                                        </td>
                                    </tr>
                                    
                                    <!-- Separator -->
                                    <tr>
                                        <td style="padding-top: 30px;">
                                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                                <tr>
                                                    <td style="border-top: 1px solid #e5e7eb;"></td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    
                                    <!-- Subtext -->
                                    <tr>
                                        <td style="color: #6b7280; font-family: Arial, sans-serif; font-size: 12px; line-height: 18px; padding-top: 20px;">
                                            If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser:
                                            <br>
                                            <a href="{{ $url }}" style="color: #4f46e5; word-break: break-all;">{{ $url }}</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td align="center" style="background-color: #f3f4f6; padding: 20px; color: #9ca3af; font-family: Arial, sans-serif; font-size: 12px;">
                                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
