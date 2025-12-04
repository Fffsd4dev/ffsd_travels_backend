<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Enquiry</title>
    <style>
        body {
            background-color: red !important;
            color: white !important;
            font-family: Arial, sans-serif;
        }
        h1, h4, p {
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <h1>New Enquiry Received</h1>
    <h4>Hi, {{ ucwords($data['reciever']) }}</h4>
    <p><strong>Title:</strong> {{ ucwords($data['title']) }}</p>
    <p><strong>Full Name:</strong> {{ ucwords($data['Fname']) }} {{ ucwords($data['Lname']) }}</p>
    <p><strong>Travel Date:</strong> {{ \Carbon\Carbon::parse($data['travel_date'])->format('F j, Y') }}</p> <!-- Format to human readable -->
    <p><strong>Return Date:</strong> {{ \Carbon\Carbon::parse($data['return_date'])->format('F j, Y') }}</p>
    <p><strong>Phone:</strong> {{ $data['phone'] }}</p>
    <p><strong>Email:</strong> {{ $data['email'] }}</p>
    <br>
    <p>** Please reach out within 6 hours **</p>
</body>
</html>
