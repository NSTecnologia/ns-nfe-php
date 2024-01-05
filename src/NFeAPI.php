<?php

require('./src/Compartilhados/Endpoints.php');
require('./src/Compartilhados/Parametros.php');
require('./src/Compartilhados/Genericos.php');


foreach (glob('./src/Requisicoes/_Genericos/*.php') as $filename) {
    include_once($filename);
}

require('./src/Requisicoes/NFe/ConsStatusProcessamentoReqNFe.php');
require('./src/Requisicoes/NFe/DownloadReqNFe.php');

require('./src/Retornos/NFe/EmitirSincronoRetNFe.php');


class NFeAPI {


    private $token;
    private $parametros;
    private $endpoints;
    private $genericos;
   
    public function __construct() {
        $this->parametros = new Parametros(1);
        $this->endpoints = new Endpoints;
        $this->genericos = new Genericos;
        $this->token = 'SEU_TOKEN_AQUI';
    }


    // Esta funcao envia um conteudo para uma URL, em requisicoes do tipo POST
    private function enviaConteudoParaAPI($conteudoAEnviar, $url, $tpConteudo){


        //Inicializa cURL para uma URL->
        $ch = curl_init($url);
       
        //Marca que vai enviar por POST(1=SIM)->
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
       
        //Passa um json para o campo de envio POST->
        curl_setopt($ch, CURLOPT_POSTFIELDS, $conteudoAEnviar);
       
        //Marca como tipo de arquivo enviado json
        if ($tpConteudo == 'json')
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'X-AUTH-TOKEN: ' . $this->token));
        else if ($tpConteudo == 'xml')
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml', 'X-AUTH-TOKEN: ' . $this->token));
        else
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain', 'X-AUTH-TOKEN: ' . $this->token));
       
        //Marca que vai receber string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
       
        //Inicia a conexao
        $result = curl_exec($ch);
       
        if (curl_error($ch)) {
            echo 'Erro na comunicacao: ' . '<br>';
            echo '<br>';
            echo '<pre>';
            var_dump(curl_getinfo($ch));
            echo '</pre>';
            echo '<br>';
            var_dump(curl_error($ch));
        }


        //Fecha a conexao
        curl_close($ch);


        return json_decode($result, true);
    }


    // Metodos especificos de NFe
    public function emitirNFeSincrono($conteudo, $tpConteudo, $CNPJ, $tpDown, $tpAmb, $caminho, $exibeNaTela) {


        $modelo = '55';
        $statusEnvio = null;
        $statusConsulta = null;
        $statusDownload = null;
        $motivo = null;
        $nsNRec = null;
        $chNFe = null;
        $cStat = null;
        $nProt = null;


        $this->genericos->gravarLinhaLog($modelo, '[EMISSAO_SINCRONA_INICIO]');


        $resposta = $this->emitirDocumento($modelo, $conteudo, $tpConteudo);
        $statusEnvio = $resposta['status'] ;


        if ($statusEnvio == 200 || $statusEnvio == -6){


            $nsNRec = $resposta['nsNRec'];


            // É necessário aguardar alguns milisegundos antes de consultar o status de processamento
            sleep($this->parametros->TEMPO_ESPERA);


            $consStatusProcessamentoReqNFe = new ConsStatusProcessamentoReqNFe();
            $consStatusProcessamentoReqNFe->CNPJ = $CNPJ;
            $consStatusProcessamentoReqNFe->nsNRec = $nsNRec;
            $consStatusProcessamentoReqNFe->tpAmb = $tpAmb;


            $resposta = $this->consultarStatusProcessamento($modelo, $consStatusProcessamentoReqNFe);
            $statusConsulta = $resposta['status'];


            // Testa se a consulta foi feita com sucesso (200)
            if ($statusConsulta == 200){


                $cStat = $resposta['cStat'];


                if ($cStat == 100 || $cStat == 150){


                    $chNFe = $resposta['chNFe'];
                    $nProt = $resposta['nProt'];
                    $motivo = $resposta['xMotivo'];


                    $downloadReqNFe = new DownloadReqNFe();
                    $downloadReqNFe->chNFe = $chNFe;
                    $downloadReqNFe->tpAmb = $tpAmb;
                    $downloadReqNFe->tpDown = $tpDown;


                    $resposta = $this->downloadDocumentoESalvar($modelo, $downloadReqNFe, $caminho, $chNFe . '-NFe', $exibeNaTela);
                    $statusDownload = $resposta['status'];


                    if ($statusDownload != 200) $motivo = $resposta['motivo'];
                }else{
                    $motivo = $resposta['xMotivo'];
                }
            }else if ($statusConsulta == -2) {


                $cStat = $resposta['cStat'];
                $motivo = $resposta['erro']['xMotivo'];


            }else{
                $motivo = $resposta['motivo'];
            }
        }
        else if ($statusEnvio == -7){


            $motivo = $resposta['motivo'];
            $nsNRec = $resposta['nsNRec'];


        }
        else if ($statusEnvio == -4 || $statusEnvio == -2) {


            $motivo = $resposta['motivo'];
            $erros = $resposta['erros'];


        }
        else if ($statusEnvio == -999 || $statusEnvio == -5) {


            $motivo = $resposta['erro']['xMotivo'];


        }
        else {
            try {
                $motivo = $resposta['motivo'];
            }catch (Exception $ex){
                $motivo = $resposta;
            }
        }


        $emitirSincronoRetNFe = new EmitirSincronoRetNFe();
        $emitirSincronoRetNFe->statusEnvio = $statusEnvio;
        $emitirSincronoRetNFe->statusConsulta = $statusConsulta;
        $emitirSincronoRetNFe->statusDownload = $statusDownload;
        $emitirSincronoRetNFe->cStat = $cStat;
        $emitirSincronoRetNFe->chNFe = $chNFe;
        $emitirSincronoRetNFe->nProt = $nProt;
        $emitirSincronoRetNFe->motivo = $motivo;
        $emitirSincronoRetNFe->nsNRec = $nsNRec;
        $emitirSincronoRetNFe->erros = $erros;


        $emitirSincronoRetNFe = array_filter((array) $emitirSincronoRetNFe);


        $retorno = json_encode($emitirSincronoRetNFe, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[JSON_RETORNO]');
        $this->genericos->gravarLinhaLog($modelo, $retorno);
        $this->genericos->gravarLinhaLog($modelo, '[EMISSAO_SINCRONA_FIM]');


        return $retorno;
    }


    // Métodos genéricos, compartilhados entre diversas funções
    public function emitirDocumento($modelo, $conteudo, $tpConteudo){
       
        switch($modelo){
           
            case '55':
                $urlEnvio = $this->endpoints->NFeEnvio;
                break;
           
            default:
                throw new Exception('Não definido endpoint de envio para o modelo ' . $modelo);        
        }


        $this->genericos->gravarLinhaLog($modelo, '[ENVIA_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $conteudo);


        $resposta = $this->enviaConteudoParaAPI($conteudo, $urlEnvio, $tpConteudo);


        $this->genericos->gravarLinhaLog($modelo, '[ENVIA_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }




    public function consultarStatusProcessamento($modelo, $consStatusProcessamentoReq){
        switch ($modelo) {
           
            case '55':
                $urlConsulta = $this->endpoints->NFeConsStatusProcessamento;
                break;
           
            default:
                throw new Exception('Não definido endpoint de consulta para o modelo ' . $modelo);
        }  


        $json = json_encode((array) $consStatusProcessamentoReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[CONSULTA_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);
       
        $resposta = $this->enviaConteudoParaAPI($json, $urlConsulta, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[CONSULTA_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function downloadDocumento($modelo, $downloadReq){
        switch ($modelo) {
           
            case '55':
                $urlDownload = $this->endpoints->NFeDownload;
                break;
           
            default:
                throw new Exception('Não definido endpoint de Download para o modelo ' . $modelo);
        }  
       
        $json = json_encode((array) $downloadReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlDownload, 'json');
        $status = $resposta['status'];


        if(($status != 200) || ($status != 100)){
            $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_RESPOSTA]');
            $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));
        }else{
            $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_STATUS]');
            $this->genericos->gravarLinhaLog($modelo, $status);
        }
        return $resposta;
    }


    public function downloadDocumentoESalvar($modelo, $downloadReq, $caminho, $nome, $exibeNaTela){
       
        $resposta = $this->downloadDocumento($modelo, $downloadReq);
        $status = $resposta['status'];
        if (($status == 200) || ($status == 100)) {
            try{
                if (strlen($caminho) > 0) if (!file_exists($caminho)) mkdir($caminho, 0777, true);
                if(substr($caminho, -1) != '/') $caminho= $caminho . '/';
            }catch(Exception $e){
                $this->genericos->gravarLinhaLog($modelo, '[CRIA_DIRETORIO] '+ $caminho);
                $this->genericos->gravarLinhaLog($modelo, $e->getMessage());
                throw new Exception('Exceção capturada: ' + $e->getMessage());
            }


            if ($modelo == '55') {
               
                if (strpos(strtoupper($downloadReq->tpDown), 'X') >= 0) {
                    $xml = $resposta['xml'];
                    $this->genericos->salvaXML($xml, $caminho, $nome);
                }
                if (strpos(strtoupper($downloadReq->tpDown), 'P') >= 0) {
                    $pdf = $resposta['pdf'];
                    $this->genericos->salvaPDF($pdf, $caminho, $nome);


                    if ($exibeNaTela) {
                        $this->genericos->exibirNaTela($caminho, $nome);
                    }  
                }
            }
        }
        return $resposta;
    }


    public function downloadEvento($modelo, $downloadEventoReq) {
        switch ($modelo){


            case '55':
                $urlDownloadEvento = $this->endpoints->NFeDownloadEvento;
                break;


            default:
                throw new Exception('Não definido endpoint de download de evento para o modelo ' + $modelo);
        }


        $json = json_encode((array) $downloadEventoReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_EVENTO_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlDownloadEvento, 'json');
        $status = $resposta['status'];


        if(($status != 200) || ($status != 100)){
            $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_EVENTO_RESPOSTA]');
            $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));
        }else{
            $this->genericos->gravarLinhaLog($modelo, '[DOWNLOAD_EVENTO_STATUS]');
            $this->genericos->gravarLinhaLog($modelo, $status);
        }


        return $resposta;
    }


    public function downloadEventoESalvar($modelo, $downloadEventoReq, $caminho, $chave, $nSeqEvento, $exibeNaTela) {
        $tpEventoSalvar = '';
        $resposta = $this->downloadEvento($modelo, $downloadEventoReq);
        $status = $resposta['status'];
        if ($status == 200 || $status == 100){


            try{
                if (strlen($caminho) > 0) if (!file_exists($caminho)) mkdir($caminho, 0777, true);
                if (substr($caminho, -1) != '/') $caminho= $caminho . '/';


            }
        catch (Exception $ex){


                $this->genericos->gravarLinhaLog($modelo, '[CRIA_DIRETORIO] '+ $caminho);
                $this->genericos->gravarLinhaLog($modelo, $ex->getMessage());
                throw new Exception('Exceção capturada: ' . $ex->getMessage());
            }




            if (strtoupper($downloadEventoReq->tpEvento) == 'CANC'){
                $tpEventoSalvar = '110111';
            }else if (strtoupper($downloadEventoReq->tpEvento) == 'ENC'){
                $tpEventoSalvar = '110110';
            }else{
                $tpEventoSalvar = '110115';
            }


            $nome = $tpEventoSalvar . $chave . $nSeqEvento . '-procEven';


            if ($modelo == 55){
               
                //Verifica quais arquivos deve salvar
                if ((strpos(strtoupper($downloadEventoReq->tpDown), 'X') >= 0) ){


                    $xml = $resposta['xml'];
                   
                    $this->genericos->salvaXML($xml, $caminho, $nome);
                }
                if ((strpos(strtoupper($downloadEventoReq->tpDown), 'P') >= 0) ){


                    $pdf = $resposta['pdf'];


                    if ($pdf != null || $pdf != ''){


                        $this->genericos->salvaPDF($pdf, $caminho, $nome);


                        if ($exibeNaTela){
                            $this->genericos->exibirNaTela($caminho, $nome);
                        }
                    }
                }
            }
        }
        return $resposta;
    }
       
    public function downloadGeral($modelo, $downloadReq, $downloadEventoReq, $caminho, $chave, $nome, $nSeqEvento, $exibeNaTela){


        $respostaDownloadDocumento = $this->downloadDocumentoESalvar($modelo, $downloadReq, $caminho, $nome, $exibeNaTela);


        $respostaDownloadEvento = $this->downloadEventoESalvar($modelo, $downloadEventoReq, $caminho, $chave, $nSeqEvento, $exibeNaTela);
       
        return array ($respostaDownloadDocumento, $respostaDownloadEvento);
    }


    public function cancelarDocumento($modelo, $cancelarReq) {
        switch ($modelo){


        case '55':
            $urlCancelamento = $this->endpoints->NFeCancelamento;
                break;


            default:
                throw new Exception('Não definido endpoint de cancelamento para o modelo ' . $modelo);


        }


        $json = json_encode((array) $cancelarReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[CANCELAMENTO_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlCancelamento, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[CANCELAMENTO_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function cancelarDocumentoESalvar($modelo, $cancelarReq, $downloadEventoReq, $caminho, $chave, $exibeNaTela){
        $resposta = $this->cancelarDocumento($modelo, $cancelarReq);
        $status = $resposta['status'];
        if ($status == 200 || $status == 135){
            $cStat = $resposta['cStat'];
            if ($cStat == 135){
                $respostaDownloadEvento = $this->downloadEventoESalvar($modelo, $downloadEventoReq, $caminho, $chave, '1', $exibeNaTela);
            }
        }
       
        return $resposta;
    }


    public function corrigirDocumento($modelo, $corrigirReq) {


        switch ($modelo){
           
            case '55':
                $urlCCe = $this->endpoints->NFeCCe;
                break;


            default:
                throw new Exception('Não definido endpoint de carta de correção para o modelo ' . $modelo);
        }


        $json = json_encode((array) $corrigirReq, JSON_UNESCAPED_UNICODE);
       
        $this->genericos->gravarLinhaLog($modelo, '[CCE_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);
       
        $resposta = $this->enviaConteudoParaAPI($json, $urlCCe, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[CCE_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function corrigirDocumentoESalvar($modelo, $corrigirReq, $downloadEventoReq, $caminho, $chave, $nSeqEvento, $exibeNaTela) {
        $resposta = $this->corrigirDocumento($modelo, $corrigirReq);
        $status = $resposta['status'];


        if ($status == 200){
                $respostaDownloadEvento = $this->downloadEventoESalvar($modelo, $downloadEventoReq, $caminho, $chave, $nSeqEvento, $exibeNaTela);
        }
        return $resposta;
    }


    public function inutilizarNumeracao($modelo, $inutilizarReq) {


        switch ($modelo){
           
            case '55':
                $urlInutilizacao = $this->endpoints->NFeInutilizacao;
                break;


            default:
                throw new Exception('Não definido endpoint de inutilização para o modelo ' . $modelo);
        }


        $json = json_encode((array) $inutilizarReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[INUTILIZAR_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlInutilizacao, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[INUTILIZAR_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function inutilizarNumeracaoESalvar($modelo, $inutilizarReq, $caminho) {
        $resposta = $this->inutilizarNumeracao($modelo, $inutilizarReq);
        $status = $resposta['status'];
        $xml = null;
 
        if ($status == 102 || $status == 200){


            $cStat = $resposta['retornoInutNFe']['cStat'];


            if ($cStat == 102){


                switch ($modelo){


                    case '55':
                        $xml = $resposta['retornoInutNFe']['xmlInut'];
                        $chave = $resposta['retornoInutNFe']['chave'];
                        $nome = $chave . '-inutNFe';
                        break;


                    default:
                        throw new Exception('Nao existe inutilização para este modelo ' . $modelo);
                }
            }
        }
        else
        {
            echo'Ocorreu um erro ao inutilizar a numeração, veja o retorno da API para mais informações';
        }


        if ($xml != null)
        {
            if (strlen($caminho) > 0) if (!file_exists($caminho)) mkdir($caminho, true, 0777);
            if(substr($caminho, -1) != '/') $caminho= $caminho . '/';
            $this->genericos->salvaXML($xml, $caminho, $nome);
        }


        return $resposta;
    }


    public function consultarCadastroContribuinte($modelo, $consCadReq) {


        switch ($modelo){
           
            case '55':
                $urlConsCad = $this->endpoints->NFeConsCad;
                break;


            default:
                throw new Exception('Não definido endpoint de consulta de cadastro para o modelo ' . $modelo);
        }


        $json = json_encode((array) $consCadReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[CONS_CAD_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);        


        $resposta = $this->enviaConteudoParaAPI($json, $urlConsCad, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[CONS_CAD_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function consultarSituacaoDocumento($modelo, $consSitReq) {
        switch ($modelo){
           
            case '55':
                $urlConsSit = $this->endpoints->NFeConsSit;
                break;


            default:
                throw new Exception('Não definido endpoint de consulta de situação para o modelo ' . $modelo);
        }


        $json = json_encode((array) $consSitReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[CONS_SIT_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlConsSit, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[CONS_SIT_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function listarNSNRecs($modelo, $listarNSNRecReq) {


        switch ($modelo){


            case '55':
                $urlListarNSNRecs = $this->endpoints->NFeListarNSNRecs;
                break;


            default:
                throw new Exception('Não definido endpoint de listagem de nsNRec para o modelo ' . $modelo);
        }


        $json = json_encode((array) $listarNSNRecReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[LISTAR_NSNRECS_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlListarNSNRecs, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[LISTAR_NSNRECS_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
    }


    public function enviarEmailDocumento($modelo, $enviarEmailReq) {
        switch ($modelo)
        {


            case '55':
                $urlEnviarEmail = $this->endpoints->NFeEnvioEmail;
                break;


            default:
                throw new Exception('Não definido endpoint de envio de e-mail para o modelo ' . $modelo);
        }


        $json = json_encode((array) $enviarEmailReq, JSON_UNESCAPED_UNICODE);


        $this->genericos->gravarLinhaLog($modelo, '[ENVIO_EMAIL_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $json);


        $resposta = $this->enviaConteudoParaAPI($json, $urlEnviarEmail, 'json');


        $this->genericos->gravarLinhaLog($modelo, '[ENVIO_EMAIL_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
   
    }


    public function previaDocumentoESalvar($conteudo, $modelo, $tpConteudo, $caminho, $nome, $exibeNaTela) {
        switch ($modelo)
        {
            case '55':
                $urlEnviarPrevia = $this->endpoints->NFePrevia;
                break;
               
            default:
                throw new Exception('Não definido endpoint de envio da Previa para o modelo ' . $modelo);
        }


        $this->genericos->gravarLinhaLog($modelo, '[ENVIA_DADOS]');
        $this->genericos->gravarLinhaLog($modelo, $conteudo);


        $resposta = $this->enviaConteudoParaAPI($conteudo, $urlEnviarPrevia, $tpConteudo);
        $status = $resposta['status'];


        if (($status == 200)){
            $pdf = $resposta['pdf'];
                    $this->genericos-s>salvaPDF($pdf, $caminho, $nome);
            $xml = $resposta['xml'];
                    $this->genericos->salvaXML($xml, $caminho, $nome);      
                   
            if ($exibeNaTela) {
                $this->genericos->exibirNaTela($caminho, $nome);
            }
        }


        $this->genericos->gravarLinhaLog($modelo, '[ENVIA_RESPOSTA]');
        $this->genericos->gravarLinhaLog($modelo, json_encode($resposta));


        return $resposta;
   
    }
}
?>



