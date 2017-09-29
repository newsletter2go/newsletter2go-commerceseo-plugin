version = 0.0.00
outfile = CommerceSeo_newsletter2go_v$(version).zip

$(version): $(outfile)

$(outfile):
	mkdir newsletter2go
	cp -r ./admin newsletter2go
	cp -r ./lang newsletter2go
	cp ./newsletter2go_api.php newsletter2go
	cp ./Nl2go_ResponseHelper.php newsletter2go
	zip -r  build.zip ./newsletter2go/*
	mv build.zip $(outfile)
	rm -r newsletter2go
clean:
	rm -rf newsletter2go
