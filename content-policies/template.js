
(function(){var toc=document.getElementById('dbr-toc');if(!toc)return;var links=[].slice.call(toc.querySelectorAll('ul a'));var secs=links.map(function(a){return document.querySelector(a.getAttribute('href'))}).filter(Boolean);
function setActive(id){links.forEach(function(a){a.classList.toggle('is-active',a.getAttribute('href')==='#'+id)})}
function onScroll(){var y=window.scrollY+140,cur=secs[0];secs.forEach(function(s){if(s.getBoundingClientRect().top+window.scrollY<=y)cur=s});if(window.innerHeight+window.scrollY>=document.documentElement.scrollHeight-4)cur=secs[secs.length-1];if(cur)setActive(cur.id)}
links.forEach(function(a){a.addEventListener('click',function(e){var t=document.querySelector(a.getAttribute('href'));if(!t)return;e.preventDefault();var off=window.innerWidth<992?90:110;window.scrollTo({top:t.getBoundingClientRect().top+window.scrollY-off+2,behavior:'smooth'});history.replaceState(null,'',a.getAttribute('href'))})});
var top=toc.querySelector('.dbr-top');if(top)top.addEventListener('click',function(e){e.preventDefault();window.scrollTo({top:0,behavior:'smooth'})});
window.addEventListener('scroll',onScroll,{passive:true});onScroll();})();
