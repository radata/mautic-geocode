<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\EventListener;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects PDOK address lookup card into the contact edit/new form.
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

        // Only inject on contact new/edit pages
        if (!preg_match('#/s/contacts/(new|edit/)#', $uri)) {
            return;
        }

        // Only hide the card if the plugin is explicitly disabled
        try {
            $integration = $this->integrationHelper->getIntegrationObject('Geocoder');
            if ($integration && !$integration->getIntegrationSettings()->getIsPublished()) {
                return;
            }
            // If integration not found (false/null), still show — plugin may not be fully registered
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
.pdok-card{background:#f5f7fa;border:1px solid #e1e5eb;border-radius:4px;padding:12px 15px;margin-bottom:15px}
.pdok-label{display:block;margin-bottom:8px;font-weight:600;font-size:13px;color:#555}
.pdok-inputs{display:flex;gap:6px;align-items:center}
.pdok-inputs .form-control{flex:none;height:34px}
#pdok-zip{width:100px}
#pdok-num{width:70px}
#pdok-add{width:55px}
#pdok-btn{flex:none;padding:6px 12px;height:34px}
.pdok-success{background:#dff0d8;border:1px solid #c3e6cb;color:#3c763d;border-radius:3px;padding:10px 12px;margin-top:10px;font-size:13px}
.pdok-success .fa-check-circle{margin-right:4px}
.pdok-error{background:#f2dede;border:1px solid #ebccd1;color:#a94442;border-radius:3px;padding:8px 12px;margin-top:10px;font-size:13px}
.pdok-retry{margin-top:6px;font-size:12px;cursor:pointer;color:#4e5d9d;background:none;border:none;padding:0;text-decoration:underline}
.pdok-retry:hover{color:#3d4a80}
#pdok-detail-toggle{margin-top:15px;border:1px solid #e1e5eb;border-radius:4px;background:#fafbfc}
#pdok-detail-toggle summary{padding:8px 12px;cursor:pointer;font-size:13px;font-weight:600;color:#777;list-style:none;display:flex;align-items:center;gap:6px}
#pdok-detail-toggle summary::-webkit-details-marker{display:none}
#pdok-detail-toggle summary .fa{transition:transform .2s;font-size:11px}
#pdok-detail-toggle[open] summary .fa{transform:rotate(90deg)}
#pdok-detail-toggle .pdok-detail-body{padding:0 12px 12px}
</style>
<script>
(function(){
    'use strict';

    // Fields to collapse (aliases)
    var DETAIL_FIELDS=['straatnaam','gemeente_code','gemeente_naam','provincie_code',
                       'house_number','house_number_addition','latitude','longitude'];

    function init(){
        var form=document.querySelector('form[name="lead"]');
        if(!form)return;
        if(document.getElementById('pdok-lookup-card'))return;

        // Find address1 field — this locates the address section
        var addr1=form.querySelector('#lead_address1,[id$="_address1"],[name="lead[address1]"]');
        if(!addr1)return;

        // Walk up to the form-group containing address1
        var addrGroup=addr1.closest('.form-group')||addr1.parentElement;
        if(!addrGroup)return;

        // Build lookup card — insert as sibling, inherits same container width
        var card=document.createElement('div');
        card.id='pdok-lookup-card';
        card.innerHTML=
            '<div class="pdok-card">'+
            '<label class="pdok-label"><i class="fa fa-map-marker"></i> Adres opzoeken (PDOK)</label>'+
            '<div class="pdok-inputs">'+
            '<input type="text" id="pdok-zip" class="form-control" placeholder="1234AB" maxlength="7" />'+
            '<input type="text" id="pdok-num" class="form-control" placeholder="Nr" maxlength="6" />'+
            '<input type="text" id="pdok-add" class="form-control" placeholder="Toe" maxlength="5" />'+
            '<button type="button" id="pdok-btn" class="btn btn-default" title="Zoek adres"><i class="fa fa-search"></i></button>'+
            '</div>'+
            '<div id="pdok-result"></div>'+
            '</div>';

        addrGroup.parentNode.insertBefore(card,addrGroup);

        // Wrap detail fields in a collapsible section
        wrapDetailFields(form);

        var btn=document.getElementById('pdok-btn');
        var zipIn=document.getElementById('pdok-zip');
        var numIn=document.getElementById('pdok-num');
        var addIn=document.getElementById('pdok-add');
        var resultDiv=document.getElementById('pdok-result');

        // Pre-fill from existing form values
        var eZip=getVal('zipcode');
        var eNum=getVal('house_number');
        var eAdd=getVal('house_number_addition');
        if(eZip)zipIn.value=eZip.replace(/\s/g,'');
        if(eNum)numIn.value=eNum;
        if(eAdd)addIn.value=eAdd;

        function doSearch(){
            var zip=zipIn.value.trim().replace(/\s/g,'');
            var num=numIn.value.trim();
            var add=addIn.value.trim();

            if(!zip||!num){
                showMsg('error','Vul postcode en huisnummer in.');
                return;
            }

            btn.disabled=true;
            btn.innerHTML='<i class="fa fa-spinner fa-spin"></i>';
            resultDiv.innerHTML='';

            var q=zip+' '+num;
            if(add)q+=add;

            var url='https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?q='+
                encodeURIComponent(q)+'&rows=1&fq=type:adres';

            fetch(url)
                .then(function(r){return r.json();})
                .then(function(data){
                    btn.disabled=false;
                    btn.innerHTML='<i class="fa fa-search"></i>';

                    if(!data.response||!data.response.docs||!data.response.docs.length){
                        showMsg('error','Geen adres gevonden voor deze combinatie.');
                        return;
                    }

                    var doc=data.response.docs[0];
                    applyToForm(doc);
                    showSuccess(doc);
                })
                .catch(function(err){
                    btn.disabled=false;
                    btn.innerHTML='<i class="fa fa-search"></i>';
                    showMsg('error','Fout bij opzoeken: '+err.message);
                });
        }

        btn.addEventListener('click',doSearch);

        [zipIn,numIn,addIn].forEach(function(inp){
            inp.addEventListener('keydown',function(e){
                if(e.key==='Enter'){e.preventDefault();doSearch();}
            });
        });

        function applyToForm(doc){
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

            setVal('address1',addr);
            setVal('city',doc.woonplaatsnaam||'');
            setVal('state',doc.provincienaam||'');
            setVal('zipcode',doc.postcode||'');
            setVal('country','Netherlands');
            setVal('latitude',lat);
            setVal('longitude',lng);
            setVal('house_number',hnum);
            setVal('house_number_addition',hlet);
            setVal('straatnaam',street);
            setVal('gemeente_code',doc.gemeentecode||'');
            setVal('gemeente_naam',doc.gemeentenaam||'');
            setVal('provincie_code',doc.provinciecode||'');
        }

        function findField(alias){
            return form.querySelector(
                '#lead_'+alias+
                ',#lead_field_'+alias+
                ',[name="lead['+alias+']"]'
            );
        }

        function getVal(alias){
            var f=findField(alias);
            return f?f.value:'';
        }

        function setVal(alias,value){
            var field=findField(alias);
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

        function showSuccess(doc){
            var street=doc.straatnaam||'';
            var hnum=doc.huisnummer?String(doc.huisnummer):'';
            var hlet=doc.huisletter||'';
            var addr=street+' '+hnum+hlet;

            resultDiv.innerHTML=
                '<div class="pdok-success">'+
                '<i class="fa fa-check-circle"></i> '+
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

    /**
     * Find detail field form-groups and wrap them in a collapsible <details>.
     */
    function wrapDetailFields(form){
        var groups=[];
        DETAIL_FIELDS.forEach(function(alias){
            var field=form.querySelector(
                '#lead_'+alias+',#lead_field_'+alias+',[name="lead['+alias+']"]'
            );
            if(!field)return;
            var fg=field.closest('.form-group');
            if(fg && groups.indexOf(fg)===-1) groups.push(fg);
        });

        if(!groups.length)return;

        // Find common parent
        var parent=groups[0].parentNode;

        var details=document.createElement('details');
        details.id='pdok-detail-toggle';
        details.innerHTML='<summary><i class="fa fa-caret-right"></i> Geocode details ('+groups.length+' velden)</summary>';

        var body=document.createElement('div');
        body.className='pdok-detail-body';

        // Insert details element before the first detail field group
        parent.insertBefore(details,groups[0]);
        details.appendChild(body);

        // Move all detail field groups into the collapsible body
        groups.forEach(function(fg){
            body.appendChild(fg);
        });
    }

    // Run when DOM is ready
    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',init);
    }else{
        init();
    }

    // Also handle AJAX-loaded forms (Mautic modals)
    if(window.jQuery){
        jQuery(document).on('ajaxComplete',function(){
            setTimeout(init,200);
        });
    }
})();
</script>
HTML;
    }
}
