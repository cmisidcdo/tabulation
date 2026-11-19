<section class="section quiz">
    <div class="row">
        <div class="col-lg-12">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Add Score</h5>
                        <div class="row">
                            <div class="col-md-12">
                                <table class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th scope="col" width="20%">Question #</th>
                                             <th scope="col" width="20%">ROUND</th>
                                            <th scope="col">Score</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Mark</td>
                                            <td>Mark</td>
                                            <td>@mdo</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <!-- Vertical Form -->
                        <div class="card mt-3">
                            <div class="card-body">
                                <div class="row d-flex justify-content-center">
                                    <div class="col-md-4">
                                        <form class="g-3 mt-3">
                                        <div class="mb-3">
                                            <label for="inputNanme4" class="form-label">Question #</label>
                                            <select name="" id="" class="form-select" id="">
                                                @for ($i = 1; $i <= 10; $i++)
                                                    <option value="{{$i}}">{{$i}}</option>
                                                @endfor
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="inputPassword4" class="form-label">Score</label>
                                            <input type="number" class="form-control" id="inputPassword4">
                                        </div>
                                        <div class="text-center">
                                            <button type="submit" class="btn btn-primary">Save</button>
                                            <button type="reset" class="btn btn-secondary">Reset</button>
                                        </div>
                                    </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
