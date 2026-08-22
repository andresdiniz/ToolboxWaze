import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect(){const id=this.element.dataset.gaId;if(!id||window.gtag)return;window.dataLayer=window.dataLayer||[];window.gtag=function(){window.dataLayer.push(arguments)};window.gtag('js',new Date());window.gtag('config',id,{anonymize_ip:true});}
}
