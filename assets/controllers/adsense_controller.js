import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect(){const client=this.element.dataset.adsenseClient;if(!client)return;const script=document.createElement('script');script.async=true;script.src=`https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${encodeURIComponent(client)}`;script.crossOrigin='anonymous';document.head.appendChild(script);try{(window.adsbygoogle=window.adsbygoogle||[]).push({});}catch{}}
}
