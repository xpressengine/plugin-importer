<div class="row">
    <div class="col-sm-12">
        <div class="panel-group">
            <form id="fSetting" class="form" method="post" action="{{ route('importer::import') }}">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <div class="panel">
                    <div class="panel-heading">
                        <div class="pull-left">
                            <h3 class="panel-title">마이그레이션</h3>
                        </div>
                    </div>
                    <div class="panel-body">
                        {{ uio('formText', ['name'=>'path', 'label'=>'데이터 파일 경로',
                            'description'=>'데이터 파일이나 배치파일의 경로를 입력하세요.
                            로컬서버에 저장된 파일 경로나 인터넷 URL을 입력할 수 있습니다.', 'value'=>old('path')]) }}

                        {{ uio('formCheckbox', ['name'=>'option', 'options'=> [
                            ['text'=>'exporter를 직접 실행하는 주소입니다.','value'=>'direct'],
                            ['text'=>'batch 파일 주소입니다.','value'=>'batch']
                        ], 'value' => old('option')]) }}

                    </div>
                    <div class="panel-footer">
                        <div class="pull-right">
                            <button type="submit" class="btn btn-primary btn-lg">실행</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
