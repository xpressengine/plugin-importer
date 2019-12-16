<div class="row">
    <div class="col-sm-12">
        <div class="panel-group">
            <div class="panel">
                <div class="panel-body">
                    <strong>최근작업</strong>
                    @if($operation['status'] !== 'running')
                        <div class="pull-right">
                            <form action="{{ route('importer::operation.delete') }}" method="post">
                                {!! csrf_field() !!}
                                <button class="xe-btn btn-danger" type="submit">내역 삭제</button>
                            </form>
                        </div>
                    @endif
                    <hr>
                    <label for="">대상 파일 경로</label>
                    <p>{{ $operation['path'] }}</p>
                    <label for="">상태</label>
                    <p>
                        @if($operation['status'] === 'successed')
                            성공
                        @elseif($operation['status'] === 'failed')
                            실패
                        @else
                            진행중
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
