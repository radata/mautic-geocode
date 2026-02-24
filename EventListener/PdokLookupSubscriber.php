<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\EventListener;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects PDOK address lookup card into the contact and company edit/new forms.
 *
 * The card provides postal code + house number + addition inputs with a search
 * button that queries PDOK Locatieserver and fills all address/geo fields.
 */
class PdokLookupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationHelper $integrationHelper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['injectLookupCard', -256],
        ];
    }

    public function injectLookupCard(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $uri     = $request->getPathInfo();

        // Inject on contact and company new/edit pages
        if (!preg_match('#/s/(contacts|companies)/(new|edit/)#', $uri)) {
            return;
        }

        // Only hide the card if the plugin is explicitly disabled
        try {
            $integration = $this->integrationHelper->getIntegrationObject('Geocoder');
            if ($integration && !$integration->getIntegrationSettings()->getIsPublished()) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Geocoder: could not check integration status, showing card anyway.');
        }

        $response = $event->getResponse();
        $content  = $response->getContent();

        if (false === $content || !str_contains($content, '</body>')) {
            return;
        }

        // Inject before the last </body> tag
        $pos = strrpos($content, '</body>');
        if (false === $pos) {
            return;
        }

        $injectable = $this->getInjectableContent();
        $content    = substr($content, 0, $pos).$injectable."\n".substr($content, $pos);
        $response->setContent($content);
    }

    private function getInjectableContent(): string
    {
        return <<<'HTML'
<!-- PDOK Address Lookup Card — MauticGeocoderBundle -->
<style>
.pdok-inputs{display:flex;gap:6px;align-items:center}
.pdok-inputs .form-control{flex:none;height:34px}
#pdok-zip{width:100px}
#pdok-num{width:70px}
#pdok-add{width:55px}
#pdok-btn{flex:none;padding:6px 10px;height:34px;display:inline-flex;align-items:center}
#pdok-btn svg{vertical-align:middle}
.pdok-success{background:#dff0d8;border:1px solid #c3e6cb;color:#3c763d;border-radius:3px;padding:8px 10px;margin-top:8px;font-size:13px}
.pdok-success svg{vertical-align:-2px;margin-right:4px}
.pdok-error{background:#f2dede;border:1px solid #ebccd1;color:#a94442;border-radius:3px;padding:8px 10px;margin-top:8px;font-size:13px}
.pdok-retry{margin-top:4px;font-size:12px;cursor:pointer;color:#4e5d9d;background:none;border:none;padding:0;text-decoration:underline}
.pdok-retry:hover{color:#3d4a80}
.pdok-detail-toggle{margin-top:0;border:1px solid #e1e5eb;border-radius:4px;background:#fafbfc}
.pdok-detail-toggle summary{padding:8px 12px;cursor:pointer;font-size:13px;font-weight:600;color:#777;list-style:none;display:flex;align-items:center;gap:6px}
.pdok-detail-toggle summary::-webkit-details-marker{display:none}
.pdok-detail-toggle summary svg{transition:transform .2s}
.pdok-detail-toggle[open] summary svg{transform:rotate(90deg)}
.pdok-detail-toggle .pdok-detail-body{padding:0 12px 12px}
@keyframes pdok-spin{to{transform:rotate(360deg)}}
.pdok-spinner{animation:pdok-spin 1s linear infinite;display:inline-block}
</style>
<style id="pdok-hide-fields">
/* Hide detail fields until JS wraps them in the <details> collapsible.
   Selector: .form-group that contains a detail field AND is NOT inside .pdok-detail-body */
.form-group:not(.pdok-detail-body .form-group):has(#lead_straatnaam),
.form-group:not(.pdok-detail-body .form-group):has(#lead_gemeente_code),
.form-group:not(.pdok-detail-body .form-group):has(#lead_gemeente_naam),
.form-group:not(.pdok-detail-body .form-group):has(#lead_provincie_code),
.form-group:not(.pdok-detail-body .form-group):has(#lead_house_number),
.form-group:not(.pdok-detail-body .form-group):has(#lead_house_number_addition),
.form-group:not(.pdok-detail-body .form-group):has(#lead_latitude),
.form-group:not(.pdok-detail-body .form-group):has(#lead_longitude),
.form-group:not(.pdok-detail-body .form-group):has(#company_companystraatnaam),
.form-group:not(.pdok-detail-body .form-group):has(#company_companygemeente_code),
.form-group:not(.pdok-detail-body .form-group):has(#company_companygemeente_naam),
.form-group:not(.pdok-detail-body .form-group):has(#company_companyprovincie_code),
.form-group:not(.pdok-detail-body .form-group):has(#company_companyhouse_number),
.form-group:not(.pdok-detail-body .form-group):has(#company_companyhouse_number_addition),
.form-group:not(.pdok-detail-body .form-group):has(#company_companylatitude),
.form-group:not(.pdok-detail-body .form-group):has(#company_companylongitude)
{display:none!important}
</style>
<script>
(function(){
    'use strict';

    // SVG icons (inline, no FA dependency)
    var ICO_SEARCH='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
    var ICO_SPIN='<span class="pdok-spinner">'+ICO_SEARCH+'</span>';
    var ICO_CHECK='<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
    var ICO_CARET='<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
    var ICO_PIN='<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-1px"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>';

    // Form configs: contact vs company
    var FORMS=[
        {
            formName:'lead',
            addrField:'address1',
            type:'contact',
            fieldMap:{
                address1:'address1', city:'city', state:'state',
                zipcode:'zipcode', country:'country',
                latitude:'latitude', longitude:'longitude',
                house_number:'house_number',
                house_number_addition:'house_number_addition',
                straatnaam:'straatnaam',
                gemeente_code:'gemeente_code',
                gemeente_naam:'gemeente_naam',
                provincie_code:'provincie_code'
            },
            prefillZip:'zipcode',
            prefillNum:'house_number',
            prefillAdd:'house_number_addition',
            detailFields:['straatnaam','gemeente_code','gemeente_naam','provincie_code',
                          'house_number','house_number_addition','latitude','longitude']
        },
        {
            formName:'company',
            addrField:'companyaddress1',
            type:'company',
            fieldMap:{
                address1:'companyaddress1', city:'companycity',
                state:'companystate', zipcode:'companyzipcode',
                country:'companycountry',
                latitude:'companylatitude', longitude:'companylongitude',
                house_number:'companyhouse_number',
                house_number_addition:'companyhouse_number_addition',
                straatnaam:'companystraatnaam',
                gemeente_code:'companygemeente_code',
                gemeente_naam:'companygemeente_naam',
                provincie_code:'companyprovincie_code'
            },
            prefillZip:'companyzipcode',
            prefillNum:'companyhouse_number',
            prefillAdd:'companyhouse_number_addition',
            detailFields:['companystraatnaam','companygemeente_code','companygemeente_naam',
                          'companyprovincie_code','companyhouse_number','companyhouse_number_addition',
                          'companylatitude','companylongitude']
        }
    ];

    function init(){
        if(document.getElementById('pdok-lookup-card'))return;

        for(var i=0;i<FORMS.length;i++){
            var cfg=FORMS[i];
            var form=document.querySelector('form[name="'+cfg.formName+'"]');
            if(!form)continue;

            var addr1=form.querySelector('#'+cfg.formName+'_'+cfg.addrField+
                ',[name="'+cfg.formName+'['+cfg.addrField+']"]');
            if(!addr1)continue;

            setupCard(form,addr1,cfg);
            return;
        }
    }

    function setupCard(form,addr1,cfg){
        var addrGroup=addr1.closest('.form-group')||addr1.parentElement;
        if(!addrGroup)return;

        var card=document.createElement('div');
        card.id='pdok-lookup-card';
        card.className='form-group mb-0';
        card.innerHTML=
            '<label class="control-label mb-xs">'+ICO_PIN+' Adres opzoeken (PDOK)</label>'+
            '<div class="row"><div class="col-sm-8">'+
            '<div class="pdok-inputs">'+
            '<input type="text" id="pdok-zip" class="form-control" placeholder="1234AB" maxlength="7" />'+
            '<input type="text" id="pdok-num" class="form-control" placeholder="Nr" maxlength="6" />'+
            '<input type="text" id="pdok-add" class="form-control" placeholder="Toe" maxlength="5" />'+
            '<button type="button" id="pdok-btn" class="btn btn-default" title="Zoek adres">'+ICO_SEARCH+'</button>'+
            '</div>'+
            '<div id="pdok-result"></div>'+
            '</div></div>';

        addrGroup.parentNode.insertBefore(card,addrGroup);

        // Wrap detail fields in collapsible section
        if(cfg.detailFields&&cfg.detailFields.length) wrapDetailFields(form,cfg);

        var btn=document.getElementById('pdok-btn');
        var zipIn=document.getElementById('pdok-zip');
        var numIn=document.getElementById('pdok-num');
        var addIn=document.getElementById('pdok-add');
        var resultDiv=document.getElementById('pdok-result');

        // Pre-fill from existing form values
        if(cfg.prefillZip){
            var eZip=getVal(form,cfg,cfg.prefillZip);
            if(eZip)zipIn.value=eZip.replace(/\s/g,'');
        }
        if(cfg.prefillNum){
            var eNum=getVal(form,cfg,cfg.prefillNum);
            if(eNum)numIn.value=eNum;
        }
        if(cfg.prefillAdd){
            var eAdd=getVal(form,cfg,cfg.prefillAdd);
            if(eAdd)addIn.value=eAdd;
        }

        function doSearch(){
            var zip=zipIn.value.trim().replace(/\s/g,'');
            var num=numIn.value.trim();
            var add=addIn.value.trim();

            if(!zip||!num){
                showMsg('error','Vul postcode en huisnummer in.');
                return;
            }

            // Dutch postal code: 4 digits + 2 letters (e.g. 1234AB)
            if(!/^\d{4}[A-Za-z]{2}$/.test(zip)){
                showMsg('error','Voer een Nederlandse postcode in (bijv. 1234AB). PDOK werkt alleen voor NL-adressen.');
                return;
            }

            btn.disabled=true;
            btn.innerHTML=ICO_SPIN;
            resultDiv.innerHTML='';

            var q=zip+' '+num;
            if(add)q+=add;

            var url='https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q='+
                encodeURIComponent(q)+'&rows=1&fq=type:adres';

            fetch(url)
                .then(function(r){return r.json();})
                .then(function(data){
                    btn.disabled=false;
                    btn.innerHTML=ICO_SEARCH;

                    if(!data.response||!data.response.docs||!data.response.docs.length){
                        showMsg('error','Geen adres gevonden voor deze combinatie.');
                        return;
                    }

                    var doc=data.response.docs[0];
                    applyToForm(form,cfg,doc);
                    showSuccess(doc);
                })
                .catch(function(err){
                    btn.disabled=false;
                    btn.innerHTML=ICO_SEARCH;
                    showMsg('error','Fout bij opzoeken: '+err.message);
                });
        }

        btn.addEventListener('click',doSearch);

        [zipIn,numIn,addIn].forEach(function(inp){
            inp.addEventListener('keydown',function(e){
                if(e.key==='Enter'){e.preventDefault();doSearch();}
            });
        });

        function showSuccess(doc){
            var street=doc.straatnaam||'';
            var hnum=doc.huisnummer?String(doc.huisnummer):'';
            var hlet=doc.huisletter||'';
            var addr=street+' '+hnum+hlet;

            resultDiv.innerHTML=
                '<div class="pdok-success">'+
                ICO_CHECK+' '+
                '<strong>'+addr+', '+(doc.postcode||'')+' '+(doc.woonplaatsnaam||'')+'</strong>'+
                '<br/><small>Gemeente: '+(doc.gemeentenaam||'')+' &bull; Provincie: '+(doc.provincienaam||'')+'</small>'+
                '</div>'+
                '<button type="button" class="pdok-retry">Opnieuw zoeken</button>';

            var retryBtn=resultDiv.querySelector('.pdok-retry');
            if(retryBtn){
                retryBtn.addEventListener('click',function(){
                    resultDiv.innerHTML='';
                    zipIn.value='';
                    numIn.value='';
                    addIn.value='';
                    zipIn.focus();
                });
            }
        }

        function showMsg(type,msg){
            resultDiv.innerHTML='<div class="pdok-'+type+'">'+msg+'</div>';
        }
    }

    function applyToForm(form,cfg,doc){
        var wkt=doc.centroide_ll||'';
        var m=wkt.match(/POINT\(([^ ]+) ([^)]+)\)/);
        var lat=m?parseFloat(m[2]).toFixed(8):'';
        var lng=m?parseFloat(m[1]).toFixed(8):'';

        var street=doc.straatnaam||'';
        var hnum=doc.huisnummer?String(doc.huisnummer):'';
        var hlet=doc.huisletter||'';

        var addr=street;
        if(hnum)addr+=' '+hnum;
        if(hlet)addr+=hlet;

        var values={
            address1:addr, city:doc.woonplaatsnaam||'',
            state:doc.provincienaam||'', zipcode:doc.postcode||'',
            country:'Netherlands', latitude:lat, longitude:lng,
            house_number:hnum, house_number_addition:hlet,
            straatnaam:street, gemeente_code:doc.gemeentecode||'',
            gemeente_naam:doc.gemeentenaam||'', provincie_code:doc.provinciecode||''
        };

        for(var key in cfg.fieldMap){
            if(!cfg.fieldMap.hasOwnProperty(key))continue;
            if(values[key]===undefined)continue;
            setVal(form,cfg,cfg.fieldMap[key],values[key]);
        }
    }

    function findField(form,cfg,alias){
        return form.querySelector(
            '#'+cfg.formName+'_'+alias+
            ',[name="'+cfg.formName+'['+alias+']"]'
        );
    }

    function getVal(form,cfg,alias){
        var f=findField(form,cfg,alias);
        return f?f.value:'';
    }

    function setVal(form,cfg,alias,value){
        var field=findField(form,cfg,alias);
        if(!field)return;

        if(field.tagName==='SELECT'){
            var opts=field.options;
            for(var i=0;i<opts.length;i++){
                if(opts[i].value===value||opts[i].textContent.trim()===value){
                    field.value=opts[i].value;
                    break;
                }
            }
            if(window.jQuery){
                window.jQuery(field).val(field.value)
                    .trigger('chosen:updated')
                    .trigger('change.select2')
                    .trigger('change');
            }
        }else{
            field.value=value;
        }

        field.dispatchEvent(new Event('input',{bubbles:true}));
        field.dispatchEvent(new Event('change',{bubbles:true}));
    }

    function wrapDetailFields(form,cfg){
        var groups=[];
        cfg.detailFields.forEach(function(alias){
            var field=findField(form,cfg,alias);
            if(!field)return;
            var fg=field.closest('.form-group');
            if(fg && groups.indexOf(fg)===-1) groups.push(fg);
        });

        if(!groups.length)return;

        var parent=groups[0].parentNode;

        var details=document.createElement('details');
        details.id='pdok-detail-toggle-'+cfg.type;
        details.className='pdok-detail-toggle';
        details.innerHTML='<summary>'+ICO_CARET+' Geocode details ('+groups.length+' velden)</summary>';

        var body=document.createElement('div');
        body.className='pdok-detail-body';

        parent.insertBefore(details,groups[0]);
        details.appendChild(body);

        groups.forEach(function(fg){
            body.appendChild(fg);
        });
    }

    // Run init when DOM is ready
    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',init);
    }else{
        init();
    }

    // Mautic loads pages via AJAX - watch for form insertion
    if(window.jQuery){
        jQuery(document).on('ajaxComplete',function(){
            setTimeout(init,300);
        });
    }

    // Fallback: MutationObserver to catch forms inserted by AJAX
    var obsTimer=null;
    var obs=new MutationObserver(function(){
        if(document.getElementById('pdok-lookup-card'))return;
        if(obsTimer)clearTimeout(obsTimer);
        obsTimer=setTimeout(function(){
            if(document.querySelector('form[name="lead"]')||document.querySelector('form[name="company"]')){
                init();
            }
        },100);
    });
    obs.observe(document.body,{childList:true,subtree:true});
})();
</script>
HTML;
    }
}
