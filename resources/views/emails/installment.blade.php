<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Installmental Payment Interest</title>
</head>
<style>
   body {
    background-color: red !important;
    color: white !important;
}

</style>
<body>
    <h1>New Installmental Payment Interest</h1>
    <h4>Hi,{{ ucwords($data['reciever']) }} </h4>
    <p><strong>Title:</strong> {{ ucwords($data['title']) }}</p>
    <p><strong>Full Name:</strong> {{ ucwords($data['fullName']) }}</p>
    <p><strong>Location:</strong> {{ ucwords($data['location']) }}</p>
    <p><strong>Destination:</strong> {{ ucwords($data['destination']) }}</p>
    <p><strong>Phone:</strong> {{$data['phone']}}</p>
     <p><strong>Email</strong>:</strong> {{$data['email']}}</p>
    <br>
    <p>** Please reach out within 6 hours **</p>
</body>
</html>
