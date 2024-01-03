# speedmap
SpeedMap: Visualize Your Internet Speed Across the Globe! 🚀 Explore and compare your connection speed to every country with this interactive world map! 


Sample map at https://rawgit.loltek.net/https://raw.githubusercontent.com/divinity76/speedmap/main/sample_speedmap.html

[<img src="https://i.imgur.com/BYu0IRs.png">](https://rawgit.loltek.net/https://raw.githubusercontent.com/divinity76/speedmap/main/sample_speedmap.html)

# requirements

- php-cli >= 8 (7.4 might work, haven't tested. patches welcome!)
- composer
- Chrome or Chromium

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


Q: Why 24 hours?

A: Speedtest.net api rate limiting, mostly. There are 250 countries, we test 5 servers from each country, each test is ran twice, and only the best result is kept, 250\*5\*2=2500 tests, add rate limiting to that, and you end up with roughly 24 hours. (*It's not really 2500, Greenland only has 1 server, [a bug](https://github.com/divinity76/speedmap/issues/1) is making it skip South Korea, and i don't think North Korea has any speed test servers at all)
