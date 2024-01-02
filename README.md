# speedmap
speedtest world map: Create a world map showing your connection speed to every country in the world!

Example speedmap at https://rawgit.loltek.net/https://raw.githubusercontent.com/divinity76/speedmap/main/sample_speedmap.html

[<img src="https://i.imgur.com/BYu0IRs.png">](https://rawgit.loltek.net/https://raw.githubusercontent.com/divinity76/speedmap/main/sample_speedmap.html)

# requirements

- php-cli >= 8 (7.4 might work, haven't tested. patches welcome!)
- composer
- Chrome or Cromium

# usage
```bash
git clone 'https://github.com/divinity76/speedmap.git' --depth 1;
cd speedmap;
composer install;
time php speedmap.php;
- now open speedmap.html in your web browser
```
# Q&A

Q: Why Chrome?

A: Speedtest.net official API does not allow you to test more than your own country and a few neighboring countries, but whatever unofficial API speedtest.net uses allow you to test (nearly?) every country in the world. It would be possible to reverse-engineer this api and use libcurl in place of chrome, but it would be a lot of work. Headless Chrome was easier than reverse-engineering the API. Patches/alternative solutions welcome!

Q: How much time does it take?

A: Roughly 24 hours. Speedmap.html is updated in real-time though, so you can check out the parital map while it is building. If you're running it on a remote server, I recommend running it in [screenie](https://manpages.ubuntu.com/manpages/trusty/man1/screenie.1.html) so generation does not stop if your SSH connection drops.
