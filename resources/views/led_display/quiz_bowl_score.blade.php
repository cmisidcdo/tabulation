<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUIZ BOWL WINNERS</title>

    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<style>
    body{

        background-color: #f6f6e9;
    }

    h5{
        color: #004a32;
        font-size: 50px;
    }

    .card1{
        border-color: #004a32;
        background-color: transparent;
        border: solid;
    }

    th{
        font-size: 40px;
        style="background-color: #f6f6e9;"
        text-align: center;
    }

    td{
        font-size: 25px;
        style="background-color: #f6f6e9;"
    }

    .table{
        font-family: Georgia, serif;
    }

</style>


<body>
   
   <div class="container mt-5">
    <div class="row mb-4">
        <div class="col text-center">
            <img src="{{ asset('img/text.png') }}" class="img-fluid" alt="Logo">
        </div>
    </div>

   </div>
   {{-- end of container --}}
    <div class="">
        @php
            $image = [
                'img/1st.png',
                'img/2nd.png',
                'img/3rd.png',
            ];
            $color = [
                '#ebba64',
                '#aaaaaa',
                '#5d412d',
            ];
            $font = [
                '80px',
                '70px',
                '60px',
            ]
        @endphp
        @php
    $chunks = $participants->chunk(ceil($participants->count() / 2));
    @endphp

    <div class="row">
        @foreach ($chunks as $table)
            <div class="col-md-6">
                <table class="table">
                    <thead>
                        <tr style="text-align: center;">
                            <th style="background-color: #f6f6e9;">Participants</th>
                            <th style="background-color: #f6f6e9;">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table as $participant)
                            <tr>
                                <td class="fw-bold px-4" style="background-color: #f6f6e9;">#{{$participant->participant_no}}  {{ $participant->school }}</td>
                                <td class="fw-bold" style="background-color: #f6f6e9; text-align: center;">{{ $participant->total_score ?? 0}}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
    
    
<div class="row my-5 px-5">
    <div class="d-flex justify-content-center align-items-center flex-wrap gap-4">
        <img src="{{ asset('img/cdo-seal.png') }}" alt="Logo 1" class="img-fluid" style="max-height: 150px;">
        <img src="{{ asset('img/seallogo.png') }}" alt="Logo 2" class="img-fluid" style="max-height: 120px;">
        <img src="{{ asset('img/risebig.png') }}" alt="Logo 3" class="img-fluid" style="max-height: 100px;">
        <img src="{{ asset('img/logo1.png') }}" alt="Logo 4" class="img-fluid" style="max-height: 100px;">
        <img src="{{ asset('img/tourismlogo.png') }}" alt="Logo 5" class="img-fluid" style="max-height: 100px;">
        <img src="{{ asset('img/ictlogo.png') }}" alt="Logo 6" class="img-fluid" style="max-height: 100px;">
        <img src="{{ asset('img/kinaadman.png') }}" alt="Logo 6" class="img-fluid" style="max-height: 200px;">
    </div>
</div>


    






    <!-- Bootstrap Bundle JS (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
